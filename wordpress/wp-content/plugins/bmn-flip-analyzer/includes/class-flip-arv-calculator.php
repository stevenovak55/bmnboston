<?php
/**
 * ARV (After Repair Value) Calculator.
 *
 * Calculates estimated ARV by finding comparable sold properties
 * from bme_listing_summary_archive and computing median $/sqft.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_ARV_Calculator {

    /**
     * Calculate ARV for a property.
     *
     * @param object $property Row from bme_listing_summary.
     * @return array {
     *     estimated_arv: float,
     *     arv_confidence: string (high|medium|low|none),
     *     comp_count: int,
     *     avg_comp_ppsf: float,
     *     comps: array of comp objects,
     *     neighborhood_avg_ppsf: float,
     *     neighborhood_ceiling: float (max sale in area),
     *     ceiling_warning: bool (true if ARV > 90% of ceiling),
     *     ceiling_pct: float (ARV as % of ceiling),
     * }
     */
    public static function calculate(object $property): array {
        $lat = (float) $property->latitude;
        $lng = (float) $property->longitude;
        $sqft = (int) $property->building_area_total;
        $beds = (int) $property->bedrooms_total;

        $result = [
            'estimated_arv'         => 0,
            'arv_confidence'        => 'none',
            'comp_count'            => 0,
            'avg_comp_ppsf'         => 0,
            'comps'                 => [],
            'neighborhood_avg_ppsf' => 0,
            'neighborhood_ceiling'  => 0,
            'ceiling_warning'       => false,
            'ceiling_pct'           => 0,
        ];

        if ($sqft <= 0 || $lat == 0 || $lng == 0) {
            return $result;
        }

        // Try 0.5 mile radius first, then expand to 1.0 mile
        $comps = self::find_comps($lat, $lng, $sqft, $beds, 0.5);
        $confidence = self::calc_confidence(count($comps));

        if (count($comps) < 3) {
            $comps_expanded = self::find_comps($lat, $lng, $sqft, $beds, 1.0);
            if (count($comps_expanded) > count($comps)) {
                $comps = $comps_expanded;
                $confidence = count($comps) >= 3 ? 'low' : self::calc_confidence(count($comps));
            }
        }

        if (empty($comps)) {
            return $result;
        }

        // Calculate median $/sqft from comps
        $ppsf_values = array_map(function ($c) {
            return (float) $c->ppsf;
        }, $comps);
        sort($ppsf_values);

        $count = count($ppsf_values);
        if ($count % 2 === 0) {
            $median_ppsf = ($ppsf_values[$count / 2 - 1] + $ppsf_values[$count / 2]) / 2;
        } else {
            $median_ppsf = $ppsf_values[floor($count / 2)];
        }

        $estimated_arv = round($median_ppsf * $sqft, 2);
        $avg_ppsf = round(array_sum($ppsf_values) / $count, 2);

        // Get neighborhood ceiling (max sale in area)
        $ceiling = self::get_neighborhood_ceiling($lat, $lng, 0.5);
        if ($ceiling <= 0) {
            // Expand search if no ceiling found at 0.5mi
            $ceiling = self::get_neighborhood_ceiling($lat, $lng, 1.0);
        }

        // Check if ARV exceeds neighborhood ceiling
        $ceiling_warning = false;
        $ceiling_pct = 0;
        if ($ceiling > 0) {
            $ceiling_pct = round(($estimated_arv / $ceiling) * 100, 1);
            $ceiling_warning = $ceiling_pct > 90;
        }

        $result['estimated_arv']         = $estimated_arv;
        $result['arv_confidence']        = $confidence;
        $result['comp_count']            = $count;
        $result['avg_comp_ppsf']         = $avg_ppsf;
        $result['comps']                 = $comps;
        $result['neighborhood_avg_ppsf'] = $avg_ppsf;
        $result['neighborhood_ceiling']  = $ceiling;
        $result['ceiling_warning']       = $ceiling_warning;
        $result['ceiling_pct']           = $ceiling_pct;

        return $result;
    }

    /**
     * Get the maximum sale price in the neighborhood (ceiling).
     * This represents the highest price the market has supported in the area.
     *
     * @param float $lat Latitude.
     * @param float $lng Longitude.
     * @param float $radius_miles Search radius.
     * @return float Maximum sale price or 0 if none found.
     */
    public static function get_neighborhood_ceiling(float $lat, float $lng, float $radius_miles = 0.5): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        $max_price = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(close_price)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              AND close_price > 0
              AND latitude BETWEEN %f AND %f
              AND longitude BETWEEN %f AND %f",
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta
        ));

        return (float) $max_price;
    }

    /** Keywords indicating renovated/new construction properties */
    const RENOVATED_KEYWORDS = [
        'renovated', 'remodeled', 'new construction', 'newly built', 'gut rehab',
        'fully updated', 'completely renovated', 'total renovation', 'brand new',
        'new build', 'custom built', 'rebuilt', 'new home', 'move-in ready',
        'turnkey', 'like new', 'updated throughout', 'fully renovated',
    ];

    /**
     * Find comparable sold SFR properties within a radius.
     * Prioritizes renovated/new construction properties for accurate ARV.
     */
    private static function find_comps(float $lat, float $lng, int $sqft, int $beds, float $radius_miles): array {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
        $listings_archive = $wpdb->prefix . 'bme_listings_archive';

        // Bounding box pre-filter (~1 degree ≈ 69 miles)
        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        $lat_min = $lat - $lat_delta;
        $lat_max = $lat + $lat_delta;
        $lng_min = $lng - $lng_delta;
        $lng_max = $lng + $lng_delta;

        $sqft_min = (int) ($sqft * 0.8);
        $sqft_max = (int) ($sqft * 1.2);
        $beds_min = max(1, $beds - 1);
        $beds_max = $beds + 1;

        // Build CASE statement for renovation scoring
        // Note: Use %% for literal % in wpdb->prepare()
        $reno_conditions = [];
        foreach (self::RENOVATED_KEYWORDS as $keyword) {
            $escaped = $wpdb->esc_like($keyword);
            $reno_conditions[] = "l.public_remarks LIKE '%%{$escaped}%%'";
        }
        $reno_case = implode(' OR ', $reno_conditions);

        // Also check for new construction (built within last 3 years)
        $current_year = (int) date('Y');
        $new_construction_year = $current_year - 3;

        // Query with renovation priority scoring
        // Priority: 1) Renovated keywords in remarks, 2) New construction (year_built >= current-3), 3) All others
        $sql = $wpdb->prepare(
            "SELECT
                s.listing_id,
                s.close_price,
                s.building_area_total AS sqft,
                s.close_price / s.building_area_total AS ppsf,
                s.close_date,
                s.bedrooms_total,
                s.bathrooms_total,
                s.city,
                CONCAT(s.street_number, ' ', s.street_name) AS address,
                s.days_on_market,
                3959 * ACOS(
                    LEAST(1.0, COS(RADIANS(%f)) * COS(RADIANS(s.latitude)) *
                    COS(RADIANS(s.longitude) - RADIANS(%f)) +
                    SIN(RADIANS(%f)) * SIN(RADIANS(s.latitude)))
                ) AS distance_miles,
                CASE
                    WHEN ({$reno_case}) THEN 2
                    WHEN s.year_built >= %d THEN 1
                    ELSE 0
                END AS reno_priority
            FROM {$archive_table} s
            LEFT JOIN {$listings_archive} l ON s.listing_id = l.listing_id
            WHERE s.standard_status = 'Closed'
              AND s.property_sub_type = 'Single Family Residence'
              AND s.close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              AND s.close_price > 0
              AND s.building_area_total > 0
              AND s.building_area_total BETWEEN %d AND %d
              AND s.bedrooms_total BETWEEN %d AND %d
              AND s.latitude BETWEEN %f AND %f
              AND s.longitude BETWEEN %f AND %f
            HAVING distance_miles <= %f
            ORDER BY reno_priority DESC, s.close_date DESC
            LIMIT 6",
            $lat, $lng, $lat,
            $new_construction_year,
            $sqft_min, $sqft_max,
            $beds_min, $beds_max,
            $lat_min, $lat_max,
            $lng_min, $lng_max,
            $radius_miles
        );

        $results = $wpdb->get_results($sql);
        return is_array($results) ? $results : [];
    }

    /**
     * Determine ARV confidence level.
     */
    private static function calc_confidence(int $comp_count): string {
        if ($comp_count >= 5) return 'high';
        if ($comp_count >= 3) return 'medium';
        if ($comp_count >= 1) return 'low';
        return 'none';
    }

    /**
     * Get neighborhood average $/sqft for a city (for comparison).
     */
    public static function get_city_avg_ppsf(string $city): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              AND close_price > 0
              AND building_area_total > 0
              AND city = %s",
            $city
        ));

        return round((float) $avg, 2);
    }

    /**
     * Get neighborhood price trend (recent 3 months vs 3-12 months ago).
     * Returns percent change.
     */
    public static function get_price_trend(string $city): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
              AND close_price > 0 AND building_area_total > 0
              AND city = %s",
            $city
        ));

        $older = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date BETWEEN DATE_SUB(NOW(), INTERVAL 12 MONTH) AND DATE_SUB(NOW(), INTERVAL 3 MONTH)
              AND close_price > 0 AND building_area_total > 0
              AND city = %s",
            $city
        ));

        if (!$older || $older <= 0) return 0;
        return round((($recent - $older) / $older) * 100, 1);
    }

    /**
     * Get area average DOM for sold SFR in a city.
     */
    public static function get_area_avg_dom(string $city): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(days_on_market)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              AND days_on_market > 0
              AND city = %s",
            $city
        ));

        return round((float) $avg, 0);
    }

    /**
     * Count sold SFR comps near a location within radius and timeframe.
     */
    public static function count_nearby_comps(float $lat, float $lng, float $radius_miles = 0.5, int $months = 6): int {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$archive_table}
            WHERE standard_status = 'Closed'
              AND property_sub_type = 'Single Family Residence'
              AND close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
              AND close_price > 0
              AND latitude BETWEEN %f AND %f
              AND longitude BETWEEN %f AND %f",
            $months,
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta
        ));
    }
}
