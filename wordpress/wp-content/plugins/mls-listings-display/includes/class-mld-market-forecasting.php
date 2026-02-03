<?php
/**
 * MLD Market Forecasting
 *
 * Provides time-series analysis, price forecasting, and appreciation trends
 * Uses statistical methods to project future market values
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Market_Forecasting {

    /**
     * Database instance
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Cache duration (6 hours for forecast data)
     *
     * @var int
     */
    private $cache_duration = 21600;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Get price appreciation forecast for a market
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months_lookback Historical months to analyze (default 24)
     * @param int $months_forecast Months to forecast ahead (default 12)
     * @return array Forecast data with trends and projections
     */
    public function get_price_forecast($city, $state = '', $property_type = 'all', $months_lookback = 24, $months_forecast = 12) {
        $cache_key = "forecast_{$city}_{$state}_{$property_type}_{$months_lookback}_{$months_forecast}";

        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Get historical price data
        $historical_data = $this->get_historical_prices($city, $state, $property_type, $months_lookback);

        if (count($historical_data) < 3) {
            return array(
                'success' => false,
                'message' => 'Insufficient historical data for forecasting',
                'min_required' => 3,
                'data_points' => count($historical_data)
            );
        }

        // Calculate trend using linear regression
        $trend = $this->calculate_linear_trend($historical_data);

        // Generate forecast
        $forecast = $this->generate_forecast($historical_data, $trend, $months_forecast);

        // Calculate appreciation metrics
        $appreciation = $this->calculate_appreciation_metrics($historical_data);

        // Determine momentum
        $momentum = $this->calculate_momentum($historical_data);

        // Calculate confidence level
        $confidence = $this->calculate_forecast_confidence($historical_data, $trend);

        $result = array(
            'success' => true,
            'historical_months' => $months_lookback,
            'forecast_months' => $months_forecast,
            'current_price' => $historical_data[count($historical_data) - 1]['avg_price'],
            'current_month' => $historical_data[count($historical_data) - 1]['month'],
            'trend' => $trend,
            'forecast' => $forecast,
            'appreciation' => $appreciation,
            'momentum' => $momentum,
            'confidence' => $confidence,
            'data_points' => count($historical_data)
        );

        $this->set_cached($cache_key, $result);

        return $result;
    }

    /**
     * Get historical price data by month
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array Monthly price data
     */
    private function get_historical_prices($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;

        $query = "
            SELECT
                DATE_FORMAT(l.close_date, '%Y-%m') as month,
                COUNT(*) as sales_count,
                AVG(l.close_price) as avg_price,
                STDDEV(l.close_price) as price_stddev,
                MIN(l.close_price) as min_price,
                MAX(l.close_price) as max_price,
                AVG(l.close_price / NULLIF(ld.building_area_total, 0)) as avg_price_per_sqft,
                AVG(l.mlspin_market_time_property) as avg_dom
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND l.close_price > 0
        ";

        $params = array($months);

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

        $query .= "
            GROUP BY month
            HAVING sales_count >= 3
            ORDER BY month ASC
        ";

        $prepared = $this->wpdb->prepare($query, $params);
        $results = $this->wpdb->get_results($prepared, ARRAY_A);

        return $results;
    }

    /**
     * Calculate linear trend using least squares regression
     *
     * @param array $data Historical price data
     * @return array Trend parameters (slope, intercept, r_squared)
     */
    private function calculate_linear_trend($data) {
        $n = count($data);

        if ($n < 2) {
            return array(
                'slope' => 0,
                'intercept' => 0,
                'r_squared' => 0,
                'monthly_change' => 0,
                'annual_change_pct' => 0
            );
        }

        // Prepare data points (x = month index, y = price)
        $sum_x = 0;
        $sum_y = 0;
        $sum_xy = 0;
        $sum_x2 = 0;
        $sum_y2 = 0;

        foreach ($data as $index => $point) {
            $x = $index;
            $y = floatval($point['avg_price']);

            $sum_x += $x;
            $sum_y += $y;
            $sum_xy += ($x * $y);
            $sum_x2 += ($x * $x);
            $sum_y2 += ($y * $y);
        }

        // Calculate slope and intercept
        $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_x2 - $sum_x * $sum_x);
        $intercept = ($sum_y - $slope * $sum_x) / $n;

        // Calculate R-squared (correlation coefficient squared)
        $ss_tot = $sum_y2 - (($sum_y * $sum_y) / $n);
        $ss_res = 0;
        foreach ($data as $index => $point) {
            $predicted = $slope * $index + $intercept;
            $actual = floatval($point['avg_price']);
            $ss_res += pow($actual - $predicted, 2);
        }
        $r_squared = $ss_tot > 0 ? (1 - ($ss_res / $ss_tot)) : 0;

        // Calculate annual percentage change
        $avg_price = $sum_y / $n;
        $annual_change_pct = $avg_price > 0 ? (($slope * 12) / $avg_price) * 100 : 0;

        return array(
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => max(0, min(1, $r_squared)), // Clamp between 0 and 1
            'monthly_change' => $slope,
            'annual_change_pct' => $annual_change_pct,
            'direction' => $slope > 0 ? 'up' : ($slope < 0 ? 'down' : 'flat')
        );
    }

    /**
     * Generate price forecast
     *
     * @param array $historical_data Historical price data
     * @param array $trend Trend parameters
     * @param int $months_ahead Number of months to forecast
     * @return array Forecast data points
     */
    private function generate_forecast($historical_data, $trend, $months_ahead) {
        $forecast = array();
        $last_index = count($historical_data) - 1;
        $current_price = floatval($historical_data[$last_index]['avg_price']);
        $current_month = $historical_data[$last_index]['month'];

        // Calculate standard deviation for confidence intervals
        $prices = array_column($historical_data, 'avg_price');
        $stddev = $this->calculate_stddev($prices);

        // Common forecast periods: 3, 6, 12 months
        $forecast_points = array(3, 6, 12);

        foreach ($forecast_points as $months) {
            if ($months > $months_ahead) {
                continue;
            }

            $future_index = $last_index + $months;
            $predicted_price = ($trend['slope'] * $future_index) + $trend['intercept'];

            // Calculate confidence interval (±2 standard deviations = ~95% confidence)
            $confidence_range = 2 * $stddev * sqrt(1 + (1 / count($historical_data)));

            $forecast_date = date('Y-m', strtotime($current_month . ' +' . $months . ' months'));

            $forecast[] = array(
                'months_ahead' => $months,
                'forecast_date' => $forecast_date,
                'predicted_price' => round($predicted_price, 0),
                'low_estimate' => round($predicted_price - $confidence_range, 0),
                'high_estimate' => round($predicted_price + $confidence_range, 0),
                'change_from_current' => round($predicted_price - $current_price, 0),
                'change_pct' => $current_price > 0 ? round((($predicted_price - $current_price) / $current_price) * 100, 2) : 0
            );
        }

        return $forecast;
    }

    /**
     * Calculate appreciation metrics
     *
     * @param array $historical_data Historical price data
     * @return array Appreciation metrics
     */
    private function calculate_appreciation_metrics($historical_data) {
        $n = count($historical_data);

        if ($n < 2) {
            return array(
                '3_month' => 0,
                '6_month' => 0,
                '12_month' => 0,
                'average_monthly' => 0
            );
        }

        $current_price = floatval($historical_data[$n - 1]['avg_price']);

        $appreciation = array();

        // 3-month appreciation
        if ($n >= 4) {
            $price_3mo_ago = floatval($historical_data[$n - 4]['avg_price']);
            $appreciation['3_month'] = $price_3mo_ago > 0 ?
                round((($current_price - $price_3mo_ago) / $price_3mo_ago) * 100, 2) : 0;
        } else {
            $appreciation['3_month'] = 0;
        }

        // 6-month appreciation
        if ($n >= 7) {
            $price_6mo_ago = floatval($historical_data[$n - 7]['avg_price']);
            $appreciation['6_month'] = $price_6mo_ago > 0 ?
                round((($current_price - $price_6mo_ago) / $price_6mo_ago) * 100, 2) : 0;
        } else {
            $appreciation['6_month'] = 0;
        }

        // 12-month appreciation
        if ($n >= 13) {
            $price_12mo_ago = floatval($historical_data[$n - 13]['avg_price']);
            $appreciation['12_month'] = $price_12mo_ago > 0 ?
                round((($current_price - $price_12mo_ago) / $price_12mo_ago) * 100, 2) : 0;
        } else if ($n >= 2) {
            // Use oldest available data
            $oldest_price = floatval($historical_data[0]['avg_price']);
            $months_diff = $n - 1;
            $appreciation['12_month'] = $oldest_price > 0 && $months_diff > 0 ?
                round(((($current_price - $oldest_price) / $oldest_price) * 100) * (12 / $months_diff), 2) : 0;
        } else {
            $appreciation['12_month'] = 0;
        }

        // Average monthly appreciation
        $oldest_price = floatval($historical_data[0]['avg_price']);
        $months_elapsed = $n - 1;
        $appreciation['average_monthly'] = $oldest_price > 0 && $months_elapsed > 0 ?
            round(((($current_price - $oldest_price) / $oldest_price) * 100) / $months_elapsed, 2) : 0;

        return $appreciation;
    }

    /**
     * Calculate price momentum indicator
     *
     * @param array $historical_data Historical price data
     * @return array Momentum analysis
     */
    private function calculate_momentum($historical_data) {
        $n = count($historical_data);

        if ($n < 6) {
            return array(
                'status' => 'insufficient_data',
                'direction' => 'unknown',
                'strength' => 0,
                'description' => 'Not enough data to determine momentum'
            );
        }

        // Compare recent trend (last 3 months) vs longer term (last 6-12 months)
        $recent_data = array_slice($historical_data, -3);
        $longer_term_data = array_slice($historical_data, max(0, $n - 12), min($n, 12));

        $recent_trend = $this->calculate_linear_trend($recent_data);
        $longer_trend = $this->calculate_linear_trend($longer_term_data);

        $recent_change_pct = $recent_trend['annual_change_pct'];
        $longer_change_pct = $longer_trend['annual_change_pct'];

        // Determine momentum
        $momentum_diff = $recent_change_pct - $longer_change_pct;

        if (abs($momentum_diff) < 2) {
            $status = 'stable';
            $description = 'Market showing steady, consistent trends';
        } else if ($momentum_diff > 5) {
            $status = 'accelerating';
            $description = 'Prices accelerating upward';
        } else if ($momentum_diff > 2) {
            $status = 'strengthening';
            $description = 'Price growth strengthening';
        } else if ($momentum_diff < -5) {
            $status = 'declining';
            $description = 'Prices declining more rapidly';
        } else {
            $status = 'weakening';
            $description = 'Price growth weakening';
        }

        $direction = $recent_trend['direction'];
        $strength = abs($recent_change_pct);

        return array(
            'status' => $status,
            'direction' => $direction,
            'strength' => round($strength, 1),
            'recent_change_pct' => round($recent_change_pct, 2),
            'longer_term_change_pct' => round($longer_change_pct, 2),
            'momentum_diff' => round($momentum_diff, 2),
            'description' => $description
        );
    }

    /**
     * Calculate forecast confidence level
     *
     * @param array $historical_data Historical price data
     * @param array $trend Trend parameters
     * @return array Confidence metrics
     */
    private function calculate_forecast_confidence($historical_data, $trend) {
        $n = count($historical_data);
        $r_squared = $trend['r_squared'];

        // Calculate coefficient of variation (volatility measure)
        $prices = array_column($historical_data, 'avg_price');
        $mean = array_sum($prices) / count($prices);
        $stddev = $this->calculate_stddev($prices);
        $coefficient_of_variation = $mean > 0 ? ($stddev / $mean) : 1;

        // Calculate confidence score (0-100)
        $confidence_score = 100;

        // Penalty for low R-squared (poor fit)
        $confidence_score -= (1 - $r_squared) * 40; // Up to 40 points

        // Penalty for high volatility
        if ($coefficient_of_variation > 0.20) {
            $confidence_score -= 20;
        } else if ($coefficient_of_variation > 0.10) {
            $confidence_score -= 10;
        }

        // Penalty for insufficient data
        if ($n < 6) {
            $confidence_score -= 20;
        } else if ($n < 12) {
            $confidence_score -= 10;
        }

        // Ensure score is 0-100
        $confidence_score = max(0, min(100, $confidence_score));

        // Determine confidence level
        if ($confidence_score >= 80) {
            $level = 'high';
            $description = 'Strong data consistency supports reliable forecast';
        } else if ($confidence_score >= 60) {
            $level = 'medium';
            $description = 'Moderate data consistency, forecast reasonably reliable';
        } else {
            $level = 'low';
            $description = 'Limited data or high volatility reduces forecast reliability';
        }

        return array(
            'score' => round($confidence_score, 0),
            'level' => $level,
            'r_squared' => round($r_squared, 3),
            'volatility' => round($coefficient_of_variation, 3),
            'data_points' => $n,
            'description' => $description
        );
    }

    /**
     * Get investment analysis for a property
     *
     * @param float $current_value Current property value
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @return array Investment metrics
     */
    public function get_investment_analysis($current_value, $city, $state = '', $property_type = 'all') {
        $forecast = $this->get_price_forecast($city, $state, $property_type, 24, 12);

        if (!$forecast['success']) {
            return array(
                'success' => false,
                'message' => $forecast['message']
            );
        }

        // Project property value based on market trends
        $annual_appreciation = $forecast['trend']['annual_change_pct'];

        $projected_values = array();
        foreach (array(1, 3, 5, 10) as $years) {
            $compound_factor = pow(1 + ($annual_appreciation / 100), $years);
            $projected_value = $current_value * $compound_factor;
            $total_appreciation = $projected_value - $current_value;

            $projected_values[$years . '_year'] = array(
                'value' => round($projected_value, 0),
                'appreciation' => round($total_appreciation, 0),
                'appreciation_pct' => round((($projected_value - $current_value) / $current_value) * 100, 2)
            );
        }

        // Calculate risk assessment
        $risk = $this->calculate_investment_risk($forecast);

        return array(
            'success' => true,
            'current_value' => $current_value,
            'annual_appreciation_rate' => round($annual_appreciation, 2),
            'projected_values' => $projected_values,
            'momentum' => $forecast['momentum'],
            'risk_assessment' => $risk,
            'confidence' => $forecast['confidence']
        );
    }

    /**
     * Calculate investment risk level
     *
     * @param array $forecast Forecast data
     * @return array Risk assessment
     */
    private function calculate_investment_risk($forecast) {
        $risk_score = 0;

        // Volatility risk
        $volatility = $forecast['confidence']['volatility'];
        if ($volatility > 0.20) {
            $risk_score += 30;
        } else if ($volatility > 0.10) {
            $risk_score += 15;
        }

        // Trend risk (declining markets = higher risk)
        if ($forecast['trend']['direction'] === 'down') {
            $risk_score += 25;
        } else if ($forecast['trend']['direction'] === 'flat') {
            $risk_score += 10;
        }

        // Momentum risk
        if ($forecast['momentum']['status'] === 'declining') {
            $risk_score += 20;
        } else if ($forecast['momentum']['status'] === 'weakening') {
            $risk_score += 10;
        }

        // Data confidence risk
        if ($forecast['confidence']['level'] === 'low') {
            $risk_score += 15;
        } else if ($forecast['confidence']['level'] === 'medium') {
            $risk_score += 5;
        }

        // Determine risk level
        if ($risk_score < 20) {
            $level = 'low';
            $description = 'Stable market with consistent appreciation trends';
        } else if ($risk_score < 40) {
            $level = 'medium';
            $description = 'Moderate market volatility, typical for real estate';
        } else if ($risk_score < 60) {
            $level = 'elevated';
            $description = 'Higher than average market uncertainty';
        } else {
            $level = 'high';
            $description = 'Significant market volatility or declining trends';
        }

        return array(
            'score' => $risk_score,
            'level' => $level,
            'description' => $description,
            'factors' => array(
                'volatility' => round($volatility, 3),
                'trend' => $forecast['trend']['direction'],
                'momentum' => $forecast['momentum']['status']
            )
        );
    }

    /**
     * Calculate standard deviation
     *
     * @param array $values Numeric values
     * @return float Standard deviation
     */
    private function calculate_stddev($values) {
        $n = count($values);
        if ($n < 2) {
            return 0;
        }

        $mean = array_sum($values) / $n;
        $sum_squares = 0;

        foreach ($values as $value) {
            $sum_squares += pow($value - $mean, 2);
        }

        return sqrt($sum_squares / ($n - 1));
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or false
     */
    private function get_cached($key) {
        $transient_key = 'mld_forecast_' . md5($key);
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
        $transient_key = 'mld_forecast_' . md5($key);
        return set_transient($transient_key, $value, $this->cache_duration);
    }

    /**
     * Clear forecast cache
     *
     * @return bool Success
     */
    public function clear_cache() {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_prefix}options
                 WHERE option_name LIKE %s",
                '_transient_mld_forecast_%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_prefix}options
                 WHERE option_name LIKE %s",
                '_transient_timeout_mld_forecast_%'
            )
        );

        return true;
    }
}
