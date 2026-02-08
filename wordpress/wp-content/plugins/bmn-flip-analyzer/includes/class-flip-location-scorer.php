<?php
/**
 * Location Quality Scorer (25% of total score).
 *
 * REVISED: Now includes road type and neighborhood ceiling support.
 *
 * Evaluates: road type, neighborhood ceiling support, school rating, price trend, comp density.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Location_Scorer {

    /** @var array Cache for school grades by lat/lng key */
    private static array $school_cache = [];

    /** @var array Cache for city-level metrics */
    private static array $city_cache = [];

    /**
     * Score location quality for flip potential.
     *
     * @param object $property Row from bme_listing_summary.
     * @param float  $price_trend Percent change in neighborhood $/sqft.
     * @param float  $area_avg_dom Average DOM for sold SFR in city.
     * @param int    $comp_density Count of sold SFR within 0.5mi/6mo.
     * @param array  $arv_data ARV calculation result with ceiling data.
     * @param array|null $photo_analysis Photo analysis result with road type.
     * @return array { score: float (0-100), factors: array }
     */
    public static function score(
        object $property,
        float $price_trend,
        float $area_avg_dom,
        int $comp_density,
        array $arv_data = [],
        ?array $photo_analysis = null
    ): array {
        $factors = [];

        // Factor 1: Road Type (25% of location score)
        // Busy roads significantly hurt resale value
        $road_type = $photo_analysis['road_type'] ?? 'unknown';
        $factors['road_type'] = self::score_road_type($road_type);

        // Factor 2: Neighborhood Ceiling Support (25% of location score)
        // Does the neighborhood support the projected ARV? Critical for flip risk.
        $ceiling_pct = $arv_data['ceiling_pct'] ?? 0;
        $factors['ceiling_support'] = self::score_ceiling_support($ceiling_pct);

        // Factor 3: Neighborhood price trend (25%)
        // Appreciation signal matters most for flip timing
        $factors['price_trend'] = self::score_price_trend($price_trend);

        // Factor 4: Comp density (15%)
        // More data = more confidence in ARV
        $factors['comp_density'] = self::score_comp_density($comp_density);

        // Factor 5: School rating (10%)
        // Resale appeal factor (less relevant for flip decision than buy-and-hold)
        $factors['school_rating'] = self::score_school_rating(
            (float) $property->latitude,
            (float) $property->longitude
        );

        // Note: tax_rate factor removed in v0.8.0 — already captured in holding costs
        // (scoring it here would double-count its impact)

        // Weighted composite
        $w = Flip_Database::get_scoring_weights()['location_sub'];
        $score = ($factors['road_type'] * $w['road_type'])
               + ($factors['ceiling_support'] * $w['ceiling'])
               + ($factors['price_trend'] * $w['trend'])
               + ($factors['comp_density'] * $w['comp_density'])
               + ($factors['school_rating'] * $w['schools']);

        return [
            'score'   => round($score, 2),
            'factors' => $factors,
        ];
    }

    /**
     * Score road type from photo analysis.
     * Busy roads significantly hurt resale value, especially for higher-priced homes.
     */
    private static function score_road_type(string $road_type): float {
        return match ($road_type) {
            'cul-de-sac'        => 100,  // Premium - quiet, safe, desirable
            'dead-end'          => 95,   // Very good - minimal traffic
            'quiet-residential' => 85,   // Good - standard residential
            'moderate-traffic'  => 60,   // Acceptable - some impact
            'busy-road'         => 25,   // Bad - significant negative
            'highway-adjacent'  => 10,   // Very bad - major negative
            default             => 70,   // Unknown - assume moderate
        };
    }

    /**
     * Score neighborhood ceiling support.
     * If ARV is near or above the highest sale in the area, it's risky.
     */
    private static function score_ceiling_support(float $ceiling_pct): float {
        if ($ceiling_pct <= 0) return 70; // No data - neutral

        return match (true) {
            $ceiling_pct <= 50  => 100,  // Well below ceiling - safe
            $ceiling_pct <= 65  => 90,   // Good margin below ceiling
            $ceiling_pct <= 75  => 80,   // Reasonable margin
            $ceiling_pct <= 85  => 60,   // Getting close to ceiling
            $ceiling_pct <= 95  => 35,   // Near ceiling - risky
            $ceiling_pct <= 105 => 15,   // At/above ceiling - very risky
            default             => 5,    // Way above ceiling - red flag
        };
    }

    /**
     * Get school grade via BMN Schools REST API.
     */
    private static function score_school_rating(float $lat, float $lng): float {
        if ($lat == 0 || $lng == 0) return 50;

        $cache_key = round($lat, 3) . ',' . round($lng, 3);
        if (isset(self::$school_cache[$cache_key])) {
            return self::$school_cache[$cache_key];
        }

        $score = 50; // Default if schools plugin unavailable

        // Guard: only call schools API if plugin is active
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active('bmn-schools/bmn-schools.php')) {
            self::$school_cache[$cache_key] = $score;
            return $score;
        }

        try {
            $request = new WP_REST_Request('GET', '/bmn-schools/v1/property/schools');
            $request->set_param('lat', $lat);
            $request->set_param('lng', $lng);
            $response = rest_do_request($request);

            if (!$response->is_error()) {
                $data = $response->get_data();
                $grade = $data['district_grade'] ?? $data['data']['district_grade'] ?? null;

                if ($grade) {
                    $score = self::grade_to_score($grade);
                }
            }
        } catch (\Throwable $e) {
            // Schools API unavailable — use default score 50
        }

        self::$school_cache[$cache_key] = $score;
        return $score;
    }

    /**
     * Convert letter grade to score.
     */
    private static function grade_to_score(string $grade): float {
        $grade_upper = strtoupper(trim($grade));
        return match (true) {
            str_starts_with($grade_upper, 'A') => 100,
            str_starts_with($grade_upper, 'B') => 75,
            str_starts_with($grade_upper, 'C') => 50,
            str_starts_with($grade_upper, 'D') => 25,
            default => 50,
        };
    }

    private static function score_price_trend(float $pct_change): float {
        return match (true) {
            $pct_change > 5  => 100,
            $pct_change > 0  => 70,
            $pct_change >= -2 => 50,
            default           => 20,
        };
    }

    private static function score_comp_density(int $count): float {
        return match (true) {
            $count >= 6 => 100,
            $count >= 4 => 80,
            $count >= 3 => 60,
            $count >= 1 => 30,
            default     => 0, // Disqualifier signal
        };
    }

    private static function score_tax_rate(object $property): float {
        // Try to get annual taxes from financial table
        global $wpdb;
        $financial_table = $wpdb->prefix . 'bme_listing_financial';

        $annual_taxes = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_annual_amount FROM {$financial_table} WHERE listing_id = %s",
            $property->listing_id
        ));

        $list_price = (float) $property->list_price;
        if (!$annual_taxes || $list_price <= 0) return 60; // Default — neutral

        $tax_rate = ((float) $annual_taxes / $list_price) * 100;

        return match (true) {
            $tax_rate < 1.0 => 100,
            $tax_rate < 1.5 => 80,
            $tax_rate < 2.0 => 60,
            default         => 40,
        };
    }

    /**
     * Pre-compute city-level metrics for all target cities at once.
     * Call this before the scoring loop for efficiency.
     */
    public static function precompute_city_metrics(array $cities): void {
        foreach ($cities as $city) {
            if (isset(self::$city_cache[$city])) continue;

            self::$city_cache[$city] = [
                'avg_ppsf'     => Flip_ARV_Calculator::get_city_avg_ppsf($city),
                'price_trend'  => Flip_ARV_Calculator::get_price_trend($city),
                'avg_dom'      => Flip_ARV_Calculator::get_area_avg_dom($city),
            ];
        }
    }

    /**
     * Get cached city metrics.
     */
    public static function get_city_metrics(string $city): array {
        return self::$city_cache[$city] ?? [
            'avg_ppsf'    => 0,
            'price_trend' => 0,
            'avg_dom'     => 0,
        ];
    }

    /**
     * Get road type display label.
     */
    public static function get_road_type_label(string $road_type): string {
        return match ($road_type) {
            'cul-de-sac'        => 'Cul-de-sac (Premium)',
            'dead-end'          => 'Dead End (Quiet)',
            'quiet-residential' => 'Quiet Residential',
            'moderate-traffic'  => 'Moderate Traffic',
            'busy-road'         => 'Busy Road (Concern)',
            'highway-adjacent'  => 'Highway Adjacent (Major Concern)',
            default             => 'Unknown',
        };
    }
}
