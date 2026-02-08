<?php
/**
 * Rental Comp-Based Rate Estimation.
 *
 * Estimates monthly rent using actual MLS rental listings (active + closed leases)
 * via the same expanding-radius pattern as the ARV calculator.
 *
 * v0.19.0: Initial implementation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Rental_Comp_Calculator {

    /** Cached lease sub-types from DB (populated once per request) */
    private static ?array $lease_sub_types_cache = null;

    /**
     * Calculate rental estimate from comparable leases.
     *
     * @param object $property Row from bme_listing_summary.
     * @return array {
     *     estimated_monthly_rent, confidence, comp_count, comps[],
     *     avg_rental_ppsf, search_radius_used, active_count, closed_count,
     *     cross_reference (vs MLS income),
     * }
     */
    public static function calculate(object $property): array {
        $lat  = (float) $property->latitude;
        $lng  = (float) $property->longitude;
        $sqft = (int) $property->building_area_total;
        $beds = (int) $property->bedrooms_total;
        $baths = (float) ($property->bathrooms_total ?? 0);
        $sub_type = $property->property_sub_type ?? 'Single Family Residence';

        $result = [
            'estimated_monthly_rent' => 0,
            'confidence'             => 'none',
            'comp_count'             => 0,
            'comps'                  => [],
            'avg_rental_ppsf'        => 0,
            'search_radius_used'     => 0,
            'active_count'           => 0,
            'closed_count'           => 0,
            'cross_reference'        => null,
        ];

        if ($sqft <= 0 || $lat == 0 || $lng == 0) {
            return $result;
        }

        // Determine lease type search order
        $exact_lease_types = self::get_compatible_lease_types($sub_type);
        $all_lease_types = null; // null = no sub-type filter (all Residential Lease)

        // Find comps with expanding radius: 0.5→1.0→2.0→3.0mi
        $comps = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, $baths, $exact_lease_types);
        $bath_relaxed = false;

        // Bathroom fallback
        if (count($comps) < 3) {
            $comps_no_bath = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, null, $exact_lease_types);
            if (count($comps_no_bath) > count($comps)) {
                $comps = $comps_no_bath;
                $bath_relaxed = true;
            }
        }

        // Type fallback: try all lease types
        if (count($comps) < 3) {
            $broad_comps = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, $baths, $all_lease_types);
            if (count($broad_comps) > count($comps)) {
                $comps = $broad_comps;
                $bath_relaxed = false;
            }
            if (count($comps) < 3) {
                $broad_no_bath = self::find_comps_with_expansion($lat, $lng, $sqft, $beds, null, $all_lease_types);
                if (count($broad_no_bath) > count($comps)) {
                    $comps = $broad_no_bath;
                    $bath_relaxed = true;
                }
            }
        }

        if (empty($comps)) {
            return $result;
        }

        // Get city avg rental $/sqft for adjustments
        $city_rental_ppsf = self::get_city_avg_rental_ppsf($property->city ?? '');
        if ($city_rental_ppsf <= 0) {
            $city_rental_ppsf = 2.00; // Fallback for Greater Boston
        }

        // Apply rental-specific adjustments to each comp
        foreach ($comps as $c) {
            $adj = self::adjust_comp_rent($c, $property, $city_rental_ppsf);
            $c->adjusted_rent     = $adj['adjusted_rent'];
            $c->adjusted_ppsf     = $sqft > 0 ? round($adj['adjusted_rent'] / (int) ($c->sqft ?: $sqft), 2) : 0;
            $c->adjustments       = $adj['adjustments'];
            $c->total_adjustment  = $adj['total_adjustment'];
        }

        // Distance + time + source weighted average
        $weighted_rent_sum = 0;
        $weight_sum = 0;
        $now_ts = current_time('timestamp');

        foreach ($comps as $c) {
            $dist = max(0.05, (float) $c->distance_miles);

            // Time decay: half-weight at 9 months (ln(2)/9 ≈ 0.077)
            $date_field = !empty($c->close_date) ? $c->close_date : ($c->listing_contract_date ?? null);
            $months_ago = 0;
            if ($date_field) {
                $comp_ts = strtotime($date_field);
                $months_ago = max(0, ($now_ts - $comp_ts) / (30 * 86400));
            }
            $time_weight = exp(-0.077 * $months_ago);

            // Source weight: closed leases more reliable than active listings
            $source_weight = ((int) ($c->is_closed ?? 0) === 1) ? 1.2 : 1.0;

            // Combined weight
            $weight = $source_weight * $time_weight / pow($dist + 0.1, 2);

            $weighted_rent_sum += (float) $c->adjusted_rent * $weight;
            $weight_sum += $weight;
        }

        $weighted_rent = $weight_sum > 0 ? round($weighted_rent_sum / $weight_sum, 2) : 0;

        // Compute stats
        $active_count = 0;
        $closed_count = 0;
        $max_radius = 0;
        foreach ($comps as $c) {
            if ((int) ($c->is_closed ?? 0) === 1) {
                $closed_count++;
            } else {
                $active_count++;
            }
            $max_radius = max($max_radius, (float) $c->distance_miles);
        }

        // Avg rental $/sqft
        $ppsf_sum = 0;
        $ppsf_count = 0;
        foreach ($comps as $c) {
            if ((float) ($c->adjusted_ppsf ?? 0) > 0) {
                $ppsf_sum += (float) $c->adjusted_ppsf;
                $ppsf_count++;
            }
        }
        $avg_ppsf = $ppsf_count > 0 ? round($ppsf_sum / $ppsf_count, 2) : 0;

        // Confidence scoring (relaxed thresholds vs ARV)
        $confidence = self::calc_confidence($comps, $now_ts);

        $result['estimated_monthly_rent'] = $weighted_rent;
        $result['confidence']             = $confidence;
        $result['comp_count']             = count($comps);
        $result['comps']                  = $comps;
        $result['avg_rental_ppsf']        = $avg_ppsf;
        $result['search_radius_used']     = round($max_radius, 2);
        $result['active_count']           = $active_count;
        $result['closed_count']           = $closed_count;
        $result['bath_filter_relaxed']    = $bath_relaxed;

        return $result;
    }

    /**
     * Cross-reference rental comp estimate with MLS gross_income.
     *
     * Called separately after calculate() when gross_income is available.
     *
     * @param float $comp_monthly Estimated monthly rent from comps.
     * @param float $mls_gross    Annual gross income from MLS data.
     * @return array { agreement, pct_diff, recommendation }
     */
    public static function cross_reference(float $comp_monthly, float $mls_gross): array {
        if ($comp_monthly <= 0 || $mls_gross <= 0) {
            return ['agreement' => 'insufficient_data', 'pct_diff' => 0, 'recommendation' => 'comp_estimate'];
        }

        $mls_monthly = $mls_gross / 12;
        $pct_diff = abs($comp_monthly - $mls_monthly) / (($comp_monthly + $mls_monthly) / 2) * 100;

        if ($pct_diff <= 15) {
            return [
                'agreement'      => 'strong',
                'pct_diff'       => round($pct_diff, 1),
                'recommendation' => 'comp_estimate',
            ];
        }
        if ($pct_diff <= 30) {
            return [
                'agreement'      => 'moderate',
                'pct_diff'       => round($pct_diff, 1),
                'recommendation' => 'comp_estimate',
            ];
        }

        // Large discrepancy — flag for manual review
        return [
            'agreement'      => 'weak',
            'pct_diff'       => round($pct_diff, 1),
            'recommendation' => $comp_monthly > $mls_monthly ? 'comp_estimate' : 'mls_income',
        ];
    }

    /**
     * Find comps with expanding radius: 0.5→1.0→2.0→3.0mi.
     */
    private static function find_comps_with_expansion(
        float $lat, float $lng, int $sqft, int $beds, ?float $baths, ?array $lease_types
    ): array {
        $radii = [0.5, 1.0, 2.0, 3.0];
        $comps = [];

        foreach ($radii as $radius) {
            $expanded = self::find_rental_comps($lat, $lng, $sqft, $beds, $baths, $radius, $lease_types);
            if (count($expanded) > count($comps)) {
                $comps = $expanded;
            }
            if (count($comps) >= 3) {
                break;
            }
        }

        return $comps;
    }

    /**
     * Find comparable rental listings (active + closed leases) within radius.
     *
     * Uses UNION to combine active summary (for-rent) and archive (leased).
     */
    private static function find_rental_comps(
        float $lat, float $lng, int $sqft, int $beds, ?float $baths,
        float $radius_miles, ?array $lease_types = null
    ): array {
        global $wpdb;
        $active_table  = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Bounding box
        $lat_delta = $radius_miles / 69.0;
        $lng_delta = $radius_miles / (69.0 * cos(deg2rad($lat)));

        // Criteria
        $sqft_min = (int) ($sqft * 0.6); // ±40% for rental (wider than ARV's ±30%)
        $sqft_max = (int) ($sqft * 1.4);
        $beds_min = max(0, $beds - 1);
        $beds_max = $beds + 1;

        // Bathroom range
        $bath_where = '';
        $bath_params = [];
        if ($baths !== null && $baths > 0) {
            $baths_min = max(1.0, $baths - 1.0);
            $baths_max = $baths + 1.0;
            $bath_where = 'AND s.bathrooms_total BETWEEN %f AND %f';
            $bath_params = [$baths_min, $baths_max];
        }

        // Lease sub-type filter
        $type_where = '';
        $type_params = [];
        if ($lease_types !== null && !empty($lease_types)) {
            $placeholders = implode(',', array_fill(0, count($lease_types), '%s'));
            $type_where = "AND s.property_sub_type IN ({$placeholders})";
            $type_params = $lease_types;
        }

        // Common WHERE for both queries
        $build_params = function () use (
            $lat, $lng, $type_params, $sqft_min, $sqft_max,
            $beds_min, $beds_max, $bath_params, $lat_delta, $lng_delta, $radius_miles
        ) {
            $params = [$lat, $lng, $lat];
            $params = array_merge($params, $type_params);
            $params = array_merge($params, [$sqft_min, $sqft_max, $beds_min, $beds_max]);
            $params = array_merge($params, $bath_params);
            $params = array_merge($params, [
                $lat - $lat_delta, $lat + $lat_delta,
                $lng - $lng_delta, $lng + $lng_delta,
                $radius_miles,
            ]);
            return $params;
        };

        $distance_expr = "3959 * ACOS(
            LEAST(1.0, COS(RADIANS(%f)) * COS(RADIANS(s.latitude)) *
            COS(RADIANS(s.longitude) - RADIANS(%f)) +
            SIN(RADIANS(%f)) * SIN(RADIANS(s.latitude)))
        )";

        $common_select = "s.listing_id,
                s.list_price AS rent_amount,
                s.building_area_total AS sqft,
                s.bedrooms_total,
                s.bathrooms_total,
                s.city,
                CONCAT(s.street_number, ' ', s.street_name) AS address,
                s.property_sub_type,
                {$distance_expr} AS distance_miles";

        $common_where = "s.property_type = 'Residential Lease'
              {$type_where}
              AND s.list_price > 0
              AND s.building_area_total > 0
              AND s.building_area_total BETWEEN %d AND %d
              AND s.bedrooms_total BETWEEN %d AND %d
              {$bath_where}
              AND s.latitude BETWEEN %f AND %f
              AND s.longitude BETWEEN %f AND %f";

        // Query 1: Active leases (for-rent)
        $active_params = $build_params();
        $active_sql = "SELECT {$common_select},
                0 AS is_closed,
                s.listing_contract_date,
                NULL AS close_date,
                NULL AS close_price
            FROM {$active_table} s
            WHERE s.standard_status = 'Active'
              AND {$common_where}
            HAVING distance_miles <= %f";

        // Query 2: Closed leases (leased) from archive — 18 month lookback
        $archive_params = $build_params();
        $archive_sql = "SELECT {$common_select},
                1 AS is_closed,
                s.listing_contract_date,
                s.close_date,
                s.close_price
            FROM {$archive_table} s
            WHERE s.standard_status = 'Closed'
              AND s.close_date >= DATE_SUB(NOW(), INTERVAL 18 MONTH)
              AND {$common_where}
            HAVING distance_miles <= %f";

        // UNION both and order
        $union_sql = "({$active_sql}) UNION ALL ({$archive_sql})
            ORDER BY distance_miles ASC, is_closed DESC
            LIMIT 15";

        $all_params = array_merge($active_params, $archive_params);

        $sql = $wpdb->prepare($union_sql, ...$all_params);
        $results = $wpdb->get_results($sql);

        if (!is_array($results)) {
            return [];
        }

        // For closed leases, use close_price as rent if available (achieved rent)
        foreach ($results as $r) {
            if ((int) $r->is_closed === 1 && !empty($r->close_price) && (float) $r->close_price > 0) {
                $r->rent_amount = (float) $r->close_price;
            }
        }

        // Dedup by address (keep most recent)
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
                // Keep closed over active, or more recent
                $existing = $deduped[$idx];
                if ((int) $comp->is_closed > (int) $existing->is_closed) {
                    $deduped[$idx] = $comp;
                } elseif ((int) $comp->is_closed === (int) $existing->is_closed) {
                    $comp_date = $comp->close_date ?? $comp->listing_contract_date ?? '';
                    $exist_date = $existing->close_date ?? $existing->listing_contract_date ?? '';
                    if ($comp_date > $exist_date) {
                        $deduped[$idx] = $comp;
                    }
                }
            } else {
                $seen[$addr] = count($deduped);
                $deduped[] = $comp;
            }
        }

        return $deduped;
    }

    /**
     * Apply rental-specific adjustments to a comp's rent amount.
     *
     * Adjustments are scaled to city average rental $/sqft.
     * Positive adjustment = subject has MORE features → comp rent adjusted UP.
     */
    private static function adjust_comp_rent(object $comp, object $subject, float $city_rental_ppsf): array {
        $adjustments = [];
        $total_adj = 0;
        $comp_rent = (float) $comp->rent_amount;

        // Rental-scaled adjustment values (monthly)
        $bed_value  = $city_rental_ppsf * 150;  // ~$300/mo at $2.00 ppsf
        $bath_value = $city_rental_ppsf * 75;   // ~$150/mo at $2.00 ppsf
        $sqft_value = $city_rental_ppsf * 0.3;  // ~$0.60/sqft diff at $2.00 ppsf

        // Bedroom adjustment
        $bed_diff = (int) ($subject->bedrooms_total ?? 0) - (int) ($comp->bedrooms_total ?? 0);
        if ($bed_diff !== 0) {
            $adj = $bed_diff * $bed_value;
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'bedrooms',
                'subject' => (int) $subject->bedrooms_total,
                'comp'    => (int) $comp->bedrooms_total,
                'adj'     => round($adj, 2),
            ];
        }

        // Bathroom adjustment
        $subj_baths = (float) ($subject->bathrooms_total ?? 0);
        $comp_baths = (float) ($comp->bathrooms_total ?? 0);
        $bath_diff = $subj_baths - $comp_baths;
        if (abs($bath_diff) >= 0.5) {
            $adj = $bath_diff * $bath_value;
            $total_adj += $adj;
            $adjustments[] = [
                'field'   => 'bathrooms',
                'subject' => $subj_baths,
                'comp'    => $comp_baths,
                'adj'     => round($adj, 2),
            ];
        }

        // Sqft adjustment (only if diff > 10%)
        $subj_sqft = (int) ($subject->building_area_total ?? 0);
        $comp_sqft = (int) ($comp->sqft ?? 0);
        if ($subj_sqft > 0 && $comp_sqft > 0) {
            $sqft_diff = $subj_sqft - $comp_sqft;
            if (abs($sqft_diff) > $subj_sqft * 0.10) {
                $adj = $sqft_diff * $sqft_value;
                // Cap sqft adjustment at ±15% of comp rent
                $adj = max(-$comp_rent * 0.15, min($comp_rent * 0.15, $adj));
                $total_adj += $adj;
                $adjustments[] = [
                    'field'   => 'sqft',
                    'subject' => $subj_sqft,
                    'comp'    => $comp_sqft,
                    'adj'     => round($adj, 2),
                ];
            }
        }

        // Cap total adjustment at ±25% of comp rent
        $max_adj = $comp_rent * 0.25;
        $total_adj = max(-$max_adj, min($max_adj, $total_adj));

        return [
            'adjusted_rent'    => round($comp_rent + $total_adj, 2),
            'adjustments'      => $adjustments,
            'total_adjustment' => round($total_adj, 2),
        ];
    }

    /**
     * Multi-factor rental confidence score.
     *
     * Same 4-factor formula as ARV with slightly relaxed thresholds:
     * high >= 65, medium >= 40, low >= 20.
     */
    private static function calc_confidence(array $comps, int $now_ts): string {
        $count = count($comps);
        if ($count === 0) return 'none';

        // Factor 1: Count (0-40 points)
        $count_score = min(40, $count * 8);

        // Factor 2: Avg distance (0-30 points)
        $avg_dist = array_sum(array_map(fn($c) => (float) $c->distance_miles, $comps)) / $count;
        $dist_score = max(0, 30 - ($avg_dist * 20)); // Relaxed: 20 vs 30 penalty per mile

        // Factor 3: Avg recency (0-20 points)
        $avg_months = array_sum(array_map(function ($c) use ($now_ts) {
            $date = $c->close_date ?? $c->listing_contract_date ?? null;
            if (!$date) return 6; // Default to 6 months for undated
            return max(0, ($now_ts - strtotime($date)) / (30 * 86400));
        }, $comps)) / $count;
        $recency_score = max(0, 20 - ($avg_months * 2.22)); // Relaxed: 2.22 vs 3.33 per month

        // Factor 4: Price variance via coefficient of variation (0-10 points)
        $rents = array_map(fn($c) => (float) ($c->adjusted_rent ?? $c->rent_amount), $comps);
        $mean = array_sum($rents) / $count;
        if ($mean > 0) {
            $variance = array_sum(array_map(fn($r) => pow($r - $mean, 2), $rents)) / $count;
            $cv = sqrt($variance) / $mean;
        } else {
            $cv = 1;
        }
        $variance_score = max(0, 10 - ($cv * 50));

        $total = $count_score + $dist_score + $recency_score + $variance_score;

        if ($total >= 65) return 'high';
        if ($total >= 40) return 'medium';
        if ($total >= 20) return 'low';
        return 'none';
    }

    /**
     * Map sale property sub-types to compatible lease sub-types.
     *
     * Uses dynamic discovery: queries DB once for distinct Residential Lease
     * sub-types, then fuzzy-maps from sale sub-types.
     */
    public static function get_compatible_lease_types(string $sale_sub_type): array {
        $lease_types = self::get_available_lease_sub_types();

        // Empty or no data — return all available
        if (empty($lease_types)) {
            return [];
        }

        // Direct match: check if the sale sub-type exists as a lease sub-type
        if (in_array($sale_sub_type, $lease_types, true)) {
            return [$sale_sub_type];
        }

        // Fuzzy mapping: sale type → lease type patterns
        $mapping = [
            'Single Family Residence' => ['Single Family', 'Detached', 'House'],
            'Condominium'             => ['Condominium', 'Condo', 'Apartment'],
            'Townhouse'               => ['Townhouse', 'Condo', 'Condominium', 'Attached'],
        ];

        // Multifamily: match by unit count keywords
        if (stripos($sale_sub_type, '2 Family') === 0 || $sale_sub_type === 'Duplex') {
            $mapping[$sale_sub_type] = ['2 Family', 'Duplex', 'Multi', 'Family'];
        } elseif (stripos($sale_sub_type, '3 Family') === 0) {
            $mapping[$sale_sub_type] = ['3 Family', 'Multi', 'Family'];
        } elseif (stripos($sale_sub_type, '4 Family') === 0) {
            $mapping[$sale_sub_type] = ['4 Family', 'Multi', 'Family'];
        } elseif ($sale_sub_type === 'Multi Family') {
            $mapping[$sale_sub_type] = ['Multi', 'Family'];
        }

        // Find matches from available lease sub-types
        $keywords = $mapping[$sale_sub_type] ?? [str_replace(' Residence', '', $sale_sub_type)];
        $matches = [];
        foreach ($lease_types as $lt) {
            foreach ($keywords as $kw) {
                if (stripos($lt, $kw) !== false) {
                    $matches[] = $lt;
                    break;
                }
            }
        }

        // If no matches, return all available lease types (broadest fallback)
        return !empty($matches) ? array_unique($matches) : $lease_types;
    }

    /**
     * Query DB for distinct Residential Lease sub-types (cached per request).
     */
    private static function get_available_lease_sub_types(): array {
        if (self::$lease_sub_types_cache !== null) {
            return self::$lease_sub_types_cache;
        }

        global $wpdb;
        $active_table  = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        $types = $wpdb->get_col(
            "SELECT DISTINCT property_sub_type FROM (
                SELECT property_sub_type FROM {$active_table}
                WHERE property_type = 'Residential Lease' AND property_sub_type IS NOT NULL
                UNION
                SELECT property_sub_type FROM {$archive_table}
                WHERE property_type = 'Residential Lease' AND property_sub_type IS NOT NULL
            ) t
            ORDER BY property_sub_type"
        );

        self::$lease_sub_types_cache = is_array($types) ? $types : [];
        return self::$lease_sub_types_cache;
    }

    /**
     * Get city average rental $/sqft/month from active and recent closed leases.
     */
    public static function get_city_avg_rental_ppsf(string $city): float {
        if (empty($city)) return 0;

        global $wpdb;
        $active_table  = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Average from both active listings and recent closed leases
        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(rent_ppsf) FROM (
                SELECT list_price / building_area_total AS rent_ppsf
                FROM {$active_table}
                WHERE property_type = 'Residential Lease'
                  AND standard_status = 'Active'
                  AND list_price > 0 AND building_area_total > 0
                  AND city = %s
                UNION ALL
                SELECT COALESCE(close_price, list_price) / building_area_total AS rent_ppsf
                FROM {$archive_table}
                WHERE property_type = 'Residential Lease'
                  AND standard_status = 'Closed'
                  AND close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  AND COALESCE(close_price, list_price) > 0
                  AND building_area_total > 0
                  AND city = %s
            ) t",
            $city, $city
        ));

        return round((float) $avg, 2);
    }
}
