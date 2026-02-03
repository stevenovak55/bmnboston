<?php
/**
 * Market Trends Analytics
 *
 * Calculates monthly/quarterly price trends, sales volume, and market statistics
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Market_Trends {

    /**
     * Calculate monthly price trends
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months to analyze
     * @return array Monthly trend data
     */
    public function calculate_monthly_trends($city, $state = '', $property_type = 'all', $months = 24) {
        global $wpdb;

        $where_conditions = array("l.standard_status = 'Closed'");
        $where_conditions[] = $wpdb->prepare("loc.city = %s", $city);

        if (!empty($state)) {
            $where_conditions[] = $wpdb->prepare("loc.state_or_province = %s", $state);
        }

        if ($property_type !== 'all') {
            $where_conditions[] = $wpdb->prepare("l.property_type = %s", $property_type);
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = "
            SELECT
                DATE_FORMAT(l.close_date, '%Y-%m') as month,
                YEAR(l.close_date) as year,
                MONTH(l.close_date) as month_num,
                COUNT(*) as sales_count,
                AVG(l.close_price) as avg_price,
                ROUND(AVG(l.close_price), 2) as avg_close_price,
                MIN(l.close_price) as min_price,
                MAX(l.close_price) as max_price,
                AVG(CASE WHEN ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    ELSE NULL END) as avg_price_per_sqft,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG((l.close_price / l.list_price) * 100) as avg_sp_lp_ratio,
                SUM(l.close_price) as total_volume
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE $where_clause
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            AND l.close_date IS NOT NULL
            AND l.close_price > 10000
            GROUP BY DATE_FORMAT(l.close_date, '%Y-%m')
            ORDER BY month ASC
        ";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Calculate month-over-month changes
        $trends = array();
        $previous_month = null;

        foreach ($results as $row) {
            $month_data = array(
                'month' => $row['month'],
                'month_name' => date('F Y', strtotime($row['month'] . '-01')),
                'sales_count' => (int)$row['sales_count'],
                'avg_close_price' => round($row['avg_close_price'], 2),
                'min_price' => round($row['min_price'], 2),
                'max_price' => round($row['max_price'], 2),
                'avg_price_per_sqft' => round($row['avg_price_per_sqft'], 2),
                'avg_dom' => round($row['avg_dom'], 1),
                'avg_sp_lp_ratio' => round($row['avg_sp_lp_ratio'], 2),
                'total_volume' => round($row['total_volume'], 2)
            );

            // Calculate month-over-month change
            if ($previous_month) {
                $price_change = $row['avg_close_price'] - $previous_month['avg_close_price'];
                $price_change_pct = ($price_change / $previous_month['avg_close_price']) * 100;

                $month_data['mom_price_change'] = round($price_change, 2);
                $month_data['mom_price_change_pct'] = round($price_change_pct, 2);
                $month_data['mom_volume_change'] = (int)$row['sales_count'] - (int)$previous_month['sales_count'];
            } else {
                $month_data['mom_price_change'] = 0;
                $month_data['mom_price_change_pct'] = 0;
                $month_data['mom_volume_change'] = 0;
            }

            $trends[] = $month_data;
            $previous_month = $row;
        }

        return $trends;
    }

    /**
     * Calculate quarterly trends
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $quarters Number of quarters to analyze
     * @return array Quarterly trend data
     */
    public function calculate_quarterly_trends($city, $state = '', $property_type = 'all', $quarters = 8) {
        global $wpdb;

        $where_conditions = array("l.standard_status = 'Closed'");
        $where_conditions[] = $wpdb->prepare("loc.city = %s", $city);

        if (!empty($state)) {
            $where_conditions[] = $wpdb->prepare("loc.state_or_province = %s", $state);
        }

        if ($property_type !== 'all') {
            $where_conditions[] = $wpdb->prepare("l.property_type = %s", $property_type);
        }

        $where_clause = implode(' AND ', $where_conditions);
        $months = $quarters * 3;

        $query = "
            SELECT
                YEAR(l.close_date) as year,
                QUARTER(l.close_date) as quarter,
                CONCAT(YEAR(l.close_date), '-Q', QUARTER(l.close_date)) as period,
                COUNT(*) as sales_count,
                AVG(l.close_price) as avg_close_price,
                MIN(l.close_price) as min_price,
                MAX(l.close_price) as max_price,
                AVG(CASE WHEN ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    ELSE NULL END) as avg_price_per_sqft,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG((l.close_price / l.list_price) * 100) as avg_sp_lp_ratio,
                SUM(l.close_price) as total_volume
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE $where_clause
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            AND l.close_date IS NOT NULL
            AND l.close_price > 10000
            GROUP BY YEAR(l.close_date), QUARTER(l.close_date)
            ORDER BY year ASC, quarter ASC
        ";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Calculate quarter-over-quarter changes
        $trends = array();
        $previous_quarter = null;

        foreach ($results as $row) {
            $quarter_name = 'Q' . $row['quarter'] . ' ' . $row['year'];

            $quarter_data = array(
                'period' => $row['period'],
                'quarter_name' => $quarter_name,
                'year' => (int)$row['year'],
                'quarter' => (int)$row['quarter'],
                'sales_count' => (int)$row['sales_count'],
                'avg_close_price' => round($row['avg_close_price'], 2),
                'min_price' => round($row['min_price'], 2),
                'max_price' => round($row['max_price'], 2),
                'avg_price_per_sqft' => round($row['avg_price_per_sqft'], 2),
                'avg_dom' => round($row['avg_dom'], 1),
                'avg_sp_lp_ratio' => round($row['avg_sp_lp_ratio'], 2),
                'total_volume' => round($row['total_volume'], 2)
            );

            // Calculate quarter-over-quarter change
            if ($previous_quarter) {
                $price_change = $row['avg_close_price'] - $previous_quarter['avg_close_price'];
                $price_change_pct = ($price_change / $previous_quarter['avg_close_price']) * 100;

                $quarter_data['qoq_price_change'] = round($price_change, 2);
                $quarter_data['qoq_price_change_pct'] = round($price_change_pct, 2);
                $quarter_data['qoq_volume_change'] = (int)$row['sales_count'] - (int)$previous_quarter['sales_count'];
            } else {
                $quarter_data['qoq_price_change'] = 0;
                $quarter_data['qoq_price_change_pct'] = 0;
                $quarter_data['qoq_volume_change'] = 0;
            }

            $trends[] = $quarter_data;
            $previous_quarter = $row;
        }

        return $trends;
    }

    /**
     * Calculate year-over-year comparison
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array YoY comparison data
     */
    public function calculate_yoy_comparison($city, $state = '', $property_type = 'all') {
        global $wpdb;

        $where_conditions = array("l.standard_status = 'Closed'");
        $where_conditions[] = $wpdb->prepare("loc.city = %s", $city);

        if (!empty($state)) {
            $where_conditions[] = $wpdb->prepare("loc.state_or_province = %s", $state);
        }

        if ($property_type !== 'all') {
            $where_conditions[] = $wpdb->prepare("l.property_type = %s", $property_type);
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = "
            SELECT
                YEAR(l.close_date) as year,
                COUNT(*) as sales_count,
                AVG(l.close_price) as avg_close_price,
                AVG(CASE WHEN ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    ELSE NULL END) as avg_price_per_sqft,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG((l.close_price / l.list_price) * 100) as avg_sp_lp_ratio,
                SUM(l.close_price) as total_volume
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE $where_clause
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 3 YEAR)
            AND l.close_date IS NOT NULL
            AND l.close_price > 10000
            GROUP BY YEAR(l.close_date)
            ORDER BY year DESC
        ";

        $results = $wpdb->get_results($query, ARRAY_A);

        if (count($results) < 2) {
            return array('error' => 'Insufficient data for year-over-year comparison');
        }

        // Calculate YoY changes
        $yoy_data = array();
        for ($i = 0; $i < count($results) - 1; $i++) {
            $current_year = $results[$i];
            $previous_year = $results[$i + 1];

            $price_change = $current_year['avg_close_price'] - $previous_year['avg_close_price'];
            $price_change_pct = ($price_change / $previous_year['avg_close_price']) * 100;

            $volume_change = $current_year['sales_count'] - $previous_year['sales_count'];
            $volume_change_pct = ($volume_change / $previous_year['sales_count']) * 100;

            $yoy_data[] = array(
                'current_year' => (int)$current_year['year'],
                'previous_year' => (int)$previous_year['year'],
                'current_avg_price' => round($current_year['avg_close_price'], 2),
                'previous_avg_price' => round($previous_year['avg_close_price'], 2),
                'price_change' => round($price_change, 2),
                'price_change_pct' => round($price_change_pct, 2),
                'current_sales_count' => (int)$current_year['sales_count'],
                'previous_sales_count' => (int)$previous_year['sales_count'],
                'volume_change' => $volume_change,
                'volume_change_pct' => round($volume_change_pct, 2),
                'current_avg_dom' => round($current_year['avg_dom'], 1),
                'previous_avg_dom' => round($previous_year['avg_dom'], 1),
                'current_sp_lp_ratio' => round($current_year['avg_sp_lp_ratio'], 2),
                'previous_sp_lp_ratio' => round($previous_year['avg_sp_lp_ratio'], 2)
            );
        }

        return $yoy_data;
    }

    /**
     * Get market summary statistics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months for rolling average
     * @return array Market summary
     */
    public function get_market_summary($city, $state = '', $property_type = 'all', $months = 12) {
        global $wpdb;

        $where_conditions = array("l.standard_status = 'Closed'");
        $where_conditions[] = $wpdb->prepare("loc.city = %s", $city);

        if (!empty($state)) {
            $where_conditions[] = $wpdb->prepare("loc.state_or_province = %s", $state);
        }

        if ($property_type !== 'all') {
            $where_conditions[] = $wpdb->prepare("l.property_type = %s", $property_type);
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = "
            SELECT
                COUNT(*) as total_sales,
                AVG(l.close_price) as avg_close_price,
                MIN(l.close_price) as min_price,
                MAX(l.close_price) as max_price,
                AVG(CASE WHEN ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    ELSE NULL END) as avg_price_per_sqft,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG((l.close_price / l.list_price) * 100) as avg_sp_lp_ratio,
                SUM(l.close_price) as total_volume,
                MIN(l.close_date) as earliest_sale,
                MAX(l.close_date) as latest_sale
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE $where_clause
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            AND l.close_date IS NOT NULL
            AND l.close_price > 10000
        ";

        $result = $wpdb->get_row($query, ARRAY_A);

        if (!$result || $result['total_sales'] == 0) {
            return array('error' => 'No sales data available');
        }

        // Calculate monthly velocity
        $date_diff = strtotime($result['latest_sale']) - strtotime($result['earliest_sale']);
        $months_span = $date_diff / (30 * 24 * 60 * 60); // Approximate months
        $monthly_velocity = $months_span > 0 ? $result['total_sales'] / $months_span : 0;

        return array(
            'period_months' => $months,
            'total_sales' => (int)$result['total_sales'],
            'avg_close_price' => round($result['avg_close_price'], 2),
            'min_price' => round($result['min_price'], 2),
            'max_price' => round($result['max_price'], 2),
            'avg_price_per_sqft' => round($result['avg_price_per_sqft'], 2),
            'avg_dom' => round($result['avg_dom'], 1),
            'avg_sp_lp_ratio' => round($result['avg_sp_lp_ratio'], 2),
            'total_volume' => round($result['total_volume'], 2),
            'monthly_sales_velocity' => round($monthly_velocity, 1),
            'earliest_sale' => $result['earliest_sale'],
            'latest_sale' => $result['latest_sale']
        );
    }

    /**
     * Get price appreciation rate
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Period for calculation
     * @return array Appreciation data
     */
    public function calculate_appreciation_rate($city, $state = '', $property_type = 'all', $months = 12) {
        $monthly_trends = $this->calculate_monthly_trends($city, $state, $property_type, $months);

        if (empty($monthly_trends) || count($monthly_trends) < 2) {
            return array('error' => 'Insufficient data for appreciation calculation');
        }

        $oldest_month = $monthly_trends[0];
        $newest_month = $monthly_trends[count($monthly_trends) - 1];

        $price_change = $newest_month['avg_close_price'] - $oldest_month['avg_close_price'];
        $appreciation_pct = ($price_change / $oldest_month['avg_close_price']) * 100;

        // Annualize the rate
        $months_span = count($monthly_trends);
        $annual_appreciation_pct = ($appreciation_pct / $months_span) * 12;

        return array(
            'period_start' => $oldest_month['month'],
            'period_end' => $newest_month['month'],
            'start_price' => $oldest_month['avg_close_price'],
            'end_price' => $newest_month['avg_close_price'],
            'total_change' => round($price_change, 2),
            'total_change_pct' => round($appreciation_pct, 2),
            'annual_appreciation_pct' => round($annual_appreciation_pct, 2),
            'months_analyzed' => $months_span
        );
    }
}
