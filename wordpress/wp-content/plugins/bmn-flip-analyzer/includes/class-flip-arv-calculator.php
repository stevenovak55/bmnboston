<?php
/**
 * ARV (After Repair Value) Calculator.
 *
 * Calculates estimated ARV by finding comparable sold properties
 * from bme_listing_summary_archive and computing adjusted weighted $/sqft.
 *
 * v0.6.0: Appraisal-style comp adjustments, bathroom filter, time-decay
 * weighting, market-scaled feature values, multi-factor confidence.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_ARV_Calculator {

    /** Keywords indicating renovated/new construction properties */
    const RENOVATED_KEYWORDS = [
        'renovated', 'remodeled', 'new construction', 'newly built', 'gut rehab',
        'fully updated', 'completely renovated', 'total renovation', 'brand new',
        'new build', 'custom built', 'rebuilt', 'new home', 'move-in ready',
        'turnkey', 'like new', 'updated throughout', 'fully renovated',
    ];

    /** Compatible property type groups for comp fallback */
    const COMPATIBLE_TYPES = [
        'Townhouse'                => ['Townhouse', 'Condominium'],
        'Condominium'              => ['Condominium', 'Townhouse'],
        'Single Family Residence'  => ['Single Family Residence'],
    ];

    /**
     * Get compatible types for comp matching, including multifamily groupings.
     *
     * For multifamily, groups by unit count (2-family, 3-family, 4-family, 5+).
     * "Multi Family" (generic) is compatible with all 2-4 unit types.
     * 2-family and small multi-family (≤2 units) also include SFR — luxury duplexes
     * are more comparable to SFRs than to typical multi-family buildings.
     * Falls back to COMPATIBLE_TYPES const for standard residential.
     *
     * @param string   $sub_type Property sub-type from MLS.
     * @param int|null $units    Number of units (from bme_listing_details), null if unknown.
     */
    public static function get_compatible_types(string $sub_type, ?int $units = null): array {
        // Check standard residential types first
        if (isset(self::COMPATIBLE_TYPES[$sub_type])) {
            return self::COMPATIBLE_TYPES[$sub_type];
        }

        // Multifamily: group by unit count
        // 2-family/duplex: include SFR — these are often valued more like SFRs
        if (stripos($sub_type, '2 Family') === 0 || $sub_type === 'Duplex') {
            return ['2 Family - 2 Units Up/Down', '2 Family - 2 Units Side by Side', 'Duplex', '2 Family - Rooming House', 'Multi Family', 'Single Family Residence'];
        }
        if (stripos($sub_type, '3 Family') === 0) {
            return ['3 Family', '3 Family - 3 Units Up/Down', '3 Family - 3 Units Side by Side', 'Multi Family'];
        }
        if (stripos($sub_type, '4 Family') === 0) {
            return ['4 Family', '4 Family - 4 Units Up/Down', '4 Family - 4 Units Side by Side', 'Multi Family'];
        }
        if (stripos($sub_type, '5') === 0 || stripos($sub_type, '5+') === 0) {
            return ['5-9 Family', '5+ Family - 5+ Units Up/Down', '5+ Family - 5+ Units Side by Side', '5+ Family - Rooming House', 'Multi Family'];
        }
        if ($sub_type === 'Multi Family') {
            $types = ['Multi Family', '2 Family - 2 Units Up/Down', '2 Family - 2 Units Side by Side', '3 Family', '3 Family - 3 Units Up/Down', '4 Family', '4 Family - 4 Units Up/Down'];
            // Include SFR for small multi-family (≤2 units) or unknown unit count
            if ($units === null || $units <= 2) {
                $types[] = 'Single Family Residence';
            }
            return $types;
        }

        return [$sub_type];
    }

    /**
     * Calculate ARV for a property.
     *
     * @param object   $property Row from bme_listing_summary.
     * @param int|null $units    Number of units (for multifamily SFR comp fallback).
     * @return array {
     *     estimated_arv, arv_confidence, comp_count, avg_comp_ppsf,
     *     comps, neighborhood_avg_ppsf, neighborhood_ceiling,
     *     ceiling_warning, ceiling_pct, avg_sale_to_list, market_strength,
     *     bath_filter_relaxed,
     * }
     */
    public static function calculate(object $property, ?int $units = null): array {
        $lat  = (float) $property->latitude;
        $lng  = (float) $property->longitude;
        $sqft = (int) $property->building_area_total;
        $beds = (int) $property->bedrooms_total;
        $baths = (float) ($property->bathrooms_total ?? 0);
        $sub_type = $property->property_sub_type ?? 'Single Family Residence';

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
            'ceiling_type_mixed'    => false,
            'avg_sale_to_list'      => 1.0,
            'market_strength'       => 'balanced',
            'market_data_limited'   => true,
            'bath_filter_relaxed'   => false,
        ];

        if ($sqft <= 0 || $lat == 0 || $lng == 0) {
            return $result;
        }

        // Determine comp type search order:
        // 1. Exact property sub-type match
        // 2. Compatible types (e.g., Townhouse ↔ Condominium)
        // 3. All residential types as last resort
        $exact_types = [$sub_type];
        $compatible_types = self::get_compatible_types($sub_type, $units);
        $all_types = null; // null = no type filter

        // For 2-family/duplex or small multi-family (≤2 units), use compatible
        // types (which include SFR) from the initial search. These properties
        // compete directly with SFRs — searching only "Multi Family" yields
        // poor comps (e.g. triple-deckers instead of luxury duplexes).
        if (stripos($sub_type, '2 Family') === 0 || $sub_type === 'Duplex'
            || ($sub_type === 'Multi Family' && ($units === null || $units <= 2))) {
            $exact_types = $compatible_types;
        }

        // Find comps with bathroom filter, expanding radius: 0.5→1.0→2.0mi
        $comps = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, $baths, $exact_types);
        $bath_relaxed = false;

        // If bathroom filter is too restrictive, fall back without it
        if (count($comps) < 3) {
            $comps_no_bath = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, null, $exact_types);
            if (count($comps_no_bath) > count($comps)) {
                $comps = $comps_no_bath;
                $bath_relaxed = true;
            }
        }

        // Type fallback: try compatible types if not enough exact-type comps
        if (count($comps) < 3 && count($compatible_types) > count($exact_types)) {
            $compat_comps = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, $baths, $compatible_types);
            if (count($compat_comps) > count($comps)) {
                $comps = $compat_comps;
                $bath_relaxed = false;
            }
            if (count($comps) < 3) {
                $compat_no_bath = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, null, $compatible_types);
                if (count($compat_no_bath) > count($comps)) {
                    $comps = $compat_no_bath;
                    $bath_relaxed = true;
                }
            }
        }

        // Last resort: all residential types (no sub-type filter)
        if (count($comps) < 3) {
            $broad_comps = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, $baths, $all_types);
            if (count($broad_comps) > count($comps)) {
                $comps = $broad_comps;
                $bath_relaxed = false;
            }
            if (count($comps) < 3) {
                $broad_no_bath = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, null, $all_types);
                if (count($broad_no_bath) > count($comps)) {
                    $comps = $broad_no_bath;
                    $bath_relaxed = true;
                }
            }
        }

        if (empty($comps)) {
            return $result;
        }

        // Get city avg $/sqft for market-scaled adjustments (type-aware)
        $city_ppsf = self::get_city_avg_ppsf($property->city ?? '', $sub_type);
        if ($city_ppsf <= 0) {
            // Fallback: try compatible types, then all
            $city_ppsf = self::get_city_avg_ppsf($property->city ?? '');
        }
        if ($city_ppsf <= 0) {
            $city_ppsf = 350; // Fallback for Greater Boston
        }

        // Apply appraisal-style adjustments to each comp
        foreach ($comps as $c) {
            $adj = self::adjust_comp_price($c, $property, $city_ppsf);
            $c->adjusted_price    = $adj['adjusted_price'];
            $c->adjusted_ppsf     = (int) $c->sqft > 0 ? $adj['adjusted_price'] / (int) $c->sqft : 0;
            $c->adjustments       = $adj['adjustments'];
            $c->total_adjustment  = $adj['total_adjustment'];
        }

        // Get price trend for time adjustment (type-aware)
        $price_trend_pct = self::get_price_trend($property->city ?? '', $sub_type);
        $monthly_rate = $price_trend_pct / 12 / 100;

        // Distance + renovation + time weighted average using adjusted $/sqft
        $weighted_ppsf_sum = 0;
        $weight_sum = 0;
        $now_ts = current_time('timestamp');

        foreach ($comps as $c) {
            $dist = max(0.05, (float) $c->distance_miles);

            // Renovation multiplier
            $reno_mult = match ((int) ($c->reno_priority ?? 0)) {
                2 => 1.3,   // Renovated — slight emphasis, not over-weighted
                1 => 1.15,  // New construction / recent build
                default => 1.0,
            };

            // Distressed sale penalty — non-arm's-length transactions (foreclosures, short sales)
            // get heavily downweighted since they sell below market value
            $distress_mult = ((int) ($c->is_distressed ?? 0) === 1) ? 0.3 : 1.0;

            // Time decay: half-weight at 6 months (ln(2)/6 ≈ 0.115)
            $close_ts = strtotime($c->close_date);
            $months_ago = max(0, ($now_ts - $close_ts) / (30 * 86400));
            $time_weight = exp(-0.115 * $months_ago);

            // Time-adjust comp price to present value
            $time_adjusted_ppsf = (float) $c->adjusted_ppsf * (1 + $monthly_rate * $months_ago);

            // Combined weight
            $weight = $reno_mult * $distress_mult * $time_weight / pow($dist + 0.1, 2);

            $weighted_ppsf_sum += $time_adjusted_ppsf * $weight;
            $weight_sum += $weight;
        }

        $weighted_ppsf = $weight_sum > 0 ? $weighted_ppsf_sum / $weight_sum : 0;
        $estimated_arv = round($weighted_ppsf * $sqft, 2);
        $avg_ppsf = round($weighted_ppsf, 2);

        // Multi-factor confidence
        $confidence = self::calc_confidence($comps);

        // Neighborhood ceiling (type-aware)
        $ceiling_type_mixed = false;
        $ceiling = self::get_neighborhood_ceiling($lat, $lng, 0.5, $sub_type, $units);
        if ($ceiling <= 0) {
            $ceiling = self::get_neighborhood_ceiling($lat, $lng, 1.0, $sub_type, $units);
        }
        // Fallback: use all types for ceiling if type-specific has no data
        if ($ceiling <= 0) {
            $ceiling = self::get_neighborhood_ceiling($lat, $lng, 1.0);
            if ($ceiling > 0) {
                $ceiling_type_mixed = true;
            }
        }

        $ceiling_warning = false;
        $ceiling_pct = 0;
        if ($ceiling > 0) {
            $ceiling_pct = round(($estimated_arv / $ceiling) * 100, 1);
            $ceiling_warning = $ceiling_pct > 90;
        }

        // Sale-to-list ratio (market strength signal)
        $stl_sum = 0;
        $stl_count = 0;
        foreach ($comps as $c) {
            if (!empty($c->comp_list_price) && (float) $c->comp_list_price > 0) {
                $stl_sum += (float) $c->close_price / (float) $c->comp_list_price;
                $stl_count++;
            }
        }
        $avg_stl = $stl_count > 0 ? round($stl_sum / $stl_count, 3) : 1.0;
        $market_data_limited = ($stl_count < 3);
        $market_strength = match (true) {
            $avg_stl >= 1.04 => 'very_hot',
            $avg_stl >= 1.01 => 'hot',
            $avg_stl >= 0.97 => 'balanced',
            $avg_stl >= 0.93 => 'soft',
            default          => 'cold',
        };
        // With limited market data, don't claim hot/very_hot — default to balanced
        if ($market_data_limited && in_array($market_strength, ['very_hot', 'hot'], true)) {
            $market_strength = 'balanced';
        }

        $result['estimated_arv']         = $estimated_arv;
        $result['arv_confidence']        = $confidence;
        $result['comp_count']            = count($comps);
        $result['avg_comp_ppsf']         = $avg_ppsf;
        $result['comps']                 = $comps;
        $result['neighborhood_avg_ppsf'] = $avg_ppsf;
        $result['neighborhood_ceiling']  = $ceiling;
        $result['ceiling_warning']       = $ceiling_warning;
        $result['ceiling_pct']           = $ceiling_pct;
        $result['ceiling_type_mixed']    = $ceiling_type_mixed;
        $result['avg_sale_to_list']      = $avg_stl;
        $result['market_strength']       = $market_strength;
        $result['market_data_limited']   = $market_data_limited;
        $result['bath_filter_relaxed']   = $bath_relaxed;

        return $result;
    }

    /**
     * Find comps with expanding radius: 0.5→1.0→2.0mi.
     *
     * @param float      $lat
     * @param float      $lng
     * @param int        $sqft
     * @param int        $beds
     * @param float|null $baths     Null to skip bathroom filter.
     * @param array|null $sub_types Property sub-types to match, null for all.
     * @return array
     */
    private static function find_comps_with_expansion(float $lat, float $lng, int $sqft, int $beds, ?float $baths, ?array $sub_types = null): array {
        $comps = self::find_comps($lat, $lng, $sqft, $beds, $baths, 0.5, $sub_types);

        if (count($comps) < 3) {
            $expanded = self::find_comps($lat, $lng, $sqft, $beds, $baths, 1.0, $sub_types);
            if (count($expanded) > count($comps)) {
                $comps = $expanded;
            }
        }

        if (count($comps) < 3) {
            $expanded = self::find_comps($lat, $lng, $sqft, $beds, $baths, 2.0, $sub_types);
            if (count($expanded) > count($comps)) {
                $comps = $expanded;
            }
        }

        return $comps;
    }

    /**
     * Apply appraisal-style adjustments to a comp's close_price.
     *
     * Adjustments are market-scaled: each feature value = city_ppsf × multiplier.
     * Positive adjustment = subject has MORE → comp price adjusted UP.
     *
     * @param object $comp       Comp row.
     * @param object $subject    Subject property row.
     * @param float  $city_ppsf  City average $/sqft for scaling.
     * @return array { adjusted_price: float, adjustments: array, total_adjustment: float }
     */
    private static function adjust_comp_price(object $comp, object $subject, float $city_ppsf): array {
        $adjustments = [];
        $total_adj = 0;
        $comp_price = (float) $comp->close_price;

        // Market-scaled adjustment values
        $bed_value       = $city_ppsf * 40;   // ~$16K at $400 ppsf
        $full_bath_value = $city_ppsf * 55;   // ~$22K at $400 ppsf
        $half_bath_value = $city_ppsf * 25;   // ~$10K at $400 ppsf
        $sqft_value      = $city_ppsf * 0.5;  // 50% of city ppsf per sqft diff
        $garage_value    = $city_ppsf * 40;   // ~$16K per garage space
        $basement_value  = $city_ppsf * 28;   // ~$11.2K presence/absence

        // Bedroom adjustment
        $bed_diff = (int) ($subject->bedrooms_total ?? 0) - (int) ($comp->bedrooms_total ?? 0);
        if ($bed_diff !== 0) {
            $adj = $bed_diff * $bed_value;
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'bedrooms',
                'subject' => (int) $subject->bedrooms_total,
                'comp'    => (int) $comp->bedrooms_total,
                'adj'     => round($adj),
            ];
        }

        // Bathroom adjustment (break into full + half increments)
        $subj_baths = (float) ($subject->bathrooms_total ?? 0);
        $comp_baths = (float) ($comp->bathrooms_total ?? 0);
        $bath_diff = $subj_baths - $comp_baths;
        if (abs($bath_diff) >= 0.5) {
            $full_diff = (int) floor(abs($bath_diff));
            $half_diff = (abs($bath_diff) - $full_diff >= 0.4) ? 1 : 0;
            $sign = $bath_diff > 0 ? 1 : -1;
            $adj = $sign * ($full_diff * $full_bath_value + $half_diff * $half_bath_value);
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'bathrooms',
                'subject' => $subj_baths,
                'comp'    => $comp_baths,
                'adj'     => round($adj),
            ];
        }

        // Sqft adjustment (only if diff > 10%)
        $subj_sqft = (int) ($subject->building_area_total ?? 0);
        $comp_sqft = (int) ($comp->sqft ?? 0);
        if ($subj_sqft > 0 && $comp_sqft > 0) {
            $sqft_diff = $subj_sqft - $comp_sqft;
            if (abs($sqft_diff) > $subj_sqft * 0.05) {
                $adj = $sqft_diff * $sqft_value;
                $adj = max(-$comp_price * 0.15, min($comp_price * 0.15, $adj));
                $total_adj += $adj;
                $adjustments[] = [
                    'field'   => 'sqft',
                    'subject' => $subj_sqft,
                    'comp'    => $comp_sqft,
                    'adj'     => round($adj),
                ];
            }
        }

        // Garage adjustment
        $subj_garage = (int) ($subject->garage_spaces ?? 0);
        $comp_garage = (int) ($comp->garage_spaces ?? 0);
        if ($subj_garage !== $comp_garage) {
            $adj = ($subj_garage - $comp_garage) * $garage_value;
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'garage',
                'subject' => $subj_garage,
                'comp'    => $comp_garage,
                'adj'     => round($adj),
            ];
        }

        // Basement adjustment
        $subj_basement = (int) ($subject->has_basement ?? 0);
        $comp_basement = (int) ($comp->has_basement ?? 0);
        if ($subj_basement !== $comp_basement) {
            $adj = ($subj_basement - $comp_basement) * $basement_value;
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'basement',
                'subject' => $subj_basement,
                'comp'    => $comp_basement,
                'adj'     => round($adj),
            ];
        }

        // Cap total adjustment at ±25% of comp price
        $max_adj = $comp_price * 0.25;
        $total_adj = max(-$max_adj, min($max_adj, $total_adj));

        return [
            'adjusted_price'   => round($comp_price + $total_adj, 2),
            'adjustments'      => $adjustments,
            'total_adjustment' => round($total_adj, 2),
        ];
    }

    /**
     * Get the P90 (90th percentile) sale price in the neighborhood (ceiling).
     *
     * Uses P90 instead of MAX to avoid outlier McMansion sales skewing
     * the ceiling. Requires minimum 3 sales for meaningful data.
     *
     * @param string|null $sub_type Property sub-type filter, null for all.
     */
    public static function get_neighborhood_ceiling(float $lat, float $lng, float $radius_miles = 0.5, ?string $sub_type = null, ?int $units = null): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        $type_filter = '';
        $type_params = [];
        if ($sub_type !== null) {
            $compatible = self::get_compatible_types($sub_type, $units);
            $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
            $type_filter = "AND property_sub_type IN ({$placeholders})";
            $type_params = $compatible;
        }

        $params = array_merge($type_params, [
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta,
        ]);

        $where = $wpdb->prepare(
            "standard_status = 'Closed'
              {$type_filter}
              AND close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              AND close_price > 0
              AND latitude BETWEEN %f AND %f
              AND longitude BETWEEN %f AND %f",
            ...$params
        );

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$archive_table} WHERE {$where}");

        if ($count < 3) {
            return 0; // Not enough sales for meaningful ceiling
        }

        // P90: skip top 10% of results
        $offset = max(0, (int) floor($count * 0.10));

        $p90_price = $wpdb->get_var(
            "SELECT close_price FROM {$archive_table}
            WHERE {$where}
            ORDER BY close_price DESC
            LIMIT 1 OFFSET {$offset}"
        );

        return (float) $p90_price;
    }

    /**
     * Find comparable sold properties within a radius.
     *
     * @param float      $lat
     * @param float      $lng
     * @param int        $sqft
     * @param int        $beds
     * @param float|null $baths     Null to skip bathroom filter.
     * @param float      $radius_miles
     * @param array|null $sub_types Property sub-types to match, null for all residential.
     * @return array
     */
    private static function find_comps(float $lat, float $lng, int $sqft, int $beds, ?float $baths, float $radius_miles, ?array $sub_types = null): array {
        global $wpdb;
        $archive_table   = $wpdb->prefix . 'bme_listing_summary_archive';
        $listings_archive = $wpdb->prefix . 'bme_listings_archive';

        // Bounding box pre-filter
        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        $sqft_min = (int) ($sqft * 0.7);
        $sqft_max = (int) ($sqft * 1.3);
        $beds_min = max(1, $beds - 1);
        $beds_max = $beds + 1;

        // Bathroom range (±1.0, min 1.0)
        $bath_where = '';
        $bath_params = [];
        if ($baths !== null && $baths > 0) {
            $baths_min = max(1.0, $baths - 1.0);
            $baths_max = $baths + 1.0;
            $bath_where = 'AND s.bathrooms_total BETWEEN %f AND %f';
            $bath_params = [$baths_min, $baths_max];
        }

        // Property sub-type filter
        $type_where = '';
        $type_params = [];
        if ($sub_types !== null && !empty($sub_types)) {
            $placeholders = implode(',', array_fill(0, count($sub_types), '%s'));
            $type_where = "AND s.property_sub_type IN ({$placeholders})";
            $type_params = $sub_types;
        }

        // Renovation keyword scoring
        $reno_conditions = [];
        foreach (self::RENOVATED_KEYWORDS as $keyword) {
            $escaped = $wpdb->esc_like($keyword);
            $reno_conditions[] = "l.public_remarks LIKE '%%{$escaped}%%'";
        }
        $reno_case = implode(' OR ', $reno_conditions);

        // Distressed sale detection (non-arm's-length transactions)
        $distress_keywords = ['foreclosure', 'bank owned', 'short sale', 'court ordered', 'reo sale', 'reo property'];
        $distress_conditions = [];
        foreach ($distress_keywords as $dkw) {
            $escaped = $wpdb->esc_like($dkw);
            $distress_conditions[] = "l.public_remarks LIKE '%%{$escaped}%%'";
        }
        $distress_case = implode(' OR ', $distress_conditions);

        $current_year = (int) wp_date('Y');
        $new_construction_year = $current_year - 3;

        // Build parameterized query
        $params = [
            $lat, $lng, $lat,
            $new_construction_year,
        ];
        $params = array_merge($params, $type_params);
        $params = array_merge($params, [
            $sqft_min, $sqft_max,
            $beds_min, $beds_max,
        ]);
        $params = array_merge($params, $bath_params);
        $params = array_merge($params, [
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta,
            $radius_miles,
        ]);

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
                s.garage_spaces,
                s.has_basement,
                s.list_price AS comp_list_price,
                3959 * ACOS(
                    LEAST(1.0, COS(RADIANS(%f)) * COS(RADIANS(s.latitude)) *
                    COS(RADIANS(s.longitude) - RADIANS(%f)) +
                    SIN(RADIANS(%f)) * SIN(RADIANS(s.latitude)))
                ) AS distance_miles,
                CASE
                    WHEN ({$reno_case}) THEN 2
                    WHEN s.year_built >= %d THEN 1
                    ELSE 0
                END AS reno_priority,
                CASE
                    WHEN ({$distress_case}) THEN 1
                    ELSE 0
                END AS is_distressed
            FROM {$archive_table} s
            LEFT JOIN {$listings_archive} l ON s.listing_id = l.listing_id
            WHERE s.standard_status = 'Closed'
              {$type_where}
              AND s.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              AND s.close_price > 0
              AND s.building_area_total > 0
              AND s.building_area_total BETWEEN %d AND %d
              AND s.bedrooms_total BETWEEN %d AND %d
              {$bath_where}
              AND s.latitude BETWEEN %f AND %f
              AND s.longitude BETWEEN %f AND %f
            HAVING distance_miles <= %f
            ORDER BY reno_priority DESC, distance_miles ASC, s.close_date DESC
            LIMIT 10",
            ...$params
        );

        $results = $wpdb->get_results($sql);
        if (!is_array($results)) return [];

        // Dedup by address — keep most recent sale (removes pre-flip prices)
        $seen = [];
        $deduped = [];
        foreach ($results as $comp) {
            $addr = strtolower(trim($comp->address ?? ''));
            if (empty($addr)) {
                $deduped[] = $comp;
                continue;
            }
            if (isset($seen[$addr])) {
                $idx = $seen[$addr];
                if ($comp->close_date > $deduped[$idx]->close_date) {
                    $deduped[$idx] = $comp;
                }
            } else {
                $seen[$addr] = count($deduped);
                $deduped[] = $comp;
            }
        }
        return $deduped;
    }

    /**
     * Multi-factor ARV confidence score.
     *
     * Factors: comp count, average distance, average recency, price variance.
     *
     * @param array $comps Array of comp objects (must have adjusted_ppsf, distance_miles, close_date).
     * @return string high|medium|low|none
     */
    private static function calc_confidence(array $comps): string {
        $count = count($comps);
        if ($count === 0) return 'none';

        // Factor 1: Count (0-40 points)
        $count_score = min(40, $count * 8);

        // Factor 2: Avg distance (0-30 points)
        $avg_dist = array_sum(array_map(fn($c) => (float) $c->distance_miles, $comps)) / $count;
        $dist_score = max(0, 30 - ($avg_dist * 30));

        // Factor 3: Avg recency (0-20 points)
        $now_ts = current_time('timestamp');
        $avg_months = array_sum(array_map(function ($c) use ($now_ts) {
            return max(0, ($now_ts - strtotime($c->close_date)) / (30 * 86400));
        }, $comps)) / $count;
        $recency_score = max(0, 20 - ($avg_months * 3.33));

        // Factor 4: Price variance via coefficient of variation (0-10 points)
        $prices = array_map(fn($c) => (float) ($c->adjusted_ppsf ?? $c->ppsf), $comps);
        $mean = array_sum($prices) / $count;
        if ($mean > 0) {
            $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $prices)) / $count;
            $cv = sqrt($variance) / $mean;
        } else {
            $cv = 1;
        }
        $variance_score = max(0, 10 - ($cv * 50));

        $total = $count_score + $dist_score + $recency_score + $variance_score;

        if ($total >= 70) return 'high';
        if ($total >= 45) return 'medium';
        if ($total >= 20) return 'low';
        return 'none';
    }

    /**
     * Get neighborhood average $/sqft for a city.
     *
     * @param string      $city
     * @param string|null $sub_type Property sub-type, null for all.
     */
    public static function get_city_avg_ppsf(string $city, ?string $sub_type = null): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $type_filter = '';
        $params = [];
        if ($sub_type !== null) {
            $compatible = self::get_compatible_types($sub_type);
            $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
            $type_filter = "AND property_sub_type IN ({$placeholders})";
            $params = $compatible;
        }
        $params[] = $city;

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              {$type_filter}
              AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              AND close_price > 0
              AND building_area_total > 0
              AND city = %s",
            ...$params
        ));

        return round((float) $avg, 2);
    }

    /**
     * Get neighborhood price trend (recent 3 months vs 3-12 months ago).
     * Returns percent change.
     *
     * @param string      $city
     * @param string|null $sub_type Property sub-type, null for all.
     */
    public static function get_price_trend(string $city, ?string $sub_type = null): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $type_filter = '';
        $type_params = [];
        if ($sub_type !== null) {
            $compatible = self::get_compatible_types($sub_type);
            $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
            $type_filter = "AND property_sub_type IN ({$placeholders})";
            $type_params = $compatible;
        }

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              {$type_filter}
              AND close_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
              AND close_price > 0 AND building_area_total > 0
              AND city = %s",
            ...array_merge($type_params, [$city])
        ));

        $older = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(close_price / building_area_total)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              {$type_filter}
              AND close_date BETWEEN DATE_SUB(NOW(), INTERVAL 12 MONTH) AND DATE_SUB(NOW(), INTERVAL 3 MONTH)
              AND close_price > 0 AND building_area_total > 0
              AND city = %s",
            ...array_merge($type_params, [$city])
        ));

        if (!$older || $older <= 0) return 0;
        return round((($recent - $older) / $older) * 100, 1);
    }

    /**
     * Get area average DOM for sold properties in a city.
     *
     * @param string      $city
     * @param string|null $sub_type Property sub-type, null for all.
     */
    public static function get_area_avg_dom(string $city, ?string $sub_type = null): float {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $type_filter = '';
        $params = [];
        if ($sub_type !== null) {
            $compatible = self::get_compatible_types($sub_type);
            $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
            $type_filter = "AND property_sub_type IN ({$placeholders})";
            $params = $compatible;
        }
        $params[] = $city;

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(days_on_market)
            FROM {$archive_table}
            WHERE standard_status = 'Closed'
              {$type_filter}
              AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              AND days_on_market > 0
              AND city = %s",
            ...$params
        ));

        return round((float) $avg, 0);
    }

    /**
     * Count sold comps near a location within radius and timeframe.
     *
     * @param string|null $sub_type Property sub-type, null for all.
     */
    public static function count_nearby_comps(float $lat, float $lng, float $radius_miles = 0.5, int $months = 6, ?string $sub_type = null): int {
        global $wpdb;
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        $type_filter = '';
        $type_params = [];
        if ($sub_type !== null) {
            $compatible = self::get_compatible_types($sub_type);
            $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
            $type_filter = "AND property_sub_type IN ({$placeholders})";
            $type_params = $compatible;
        }

        $params = array_merge($type_params, [
            $months,
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta,
        ]);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$archive_table}
            WHERE standard_status = 'Closed'
              {$type_filter}
              AND close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
              AND close_price > 0
              AND latitude BETWEEN %f AND %f
              AND longitude BETWEEN %f AND %f",
            ...$params
        ));
    }
}
