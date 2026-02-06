<?php
/**
 * Flip Analyzer Orchestrator.
 *
 * Coordinates the analysis pipeline: fetches properties, runs all scorers,
 * checks disqualifiers, computes weighted totals, and stores results.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Analyzer {

    /** Scoring weights (must sum to 1.0) */
    const WEIGHT_FINANCIAL = 0.40;
    const WEIGHT_PROPERTY  = 0.25;
    const WEIGHT_LOCATION  = 0.25;
    const WEIGHT_MARKET    = 0.10;

    /** Cost breakdown constants */
    const PURCHASE_CLOSING_PCT = 0.015;       // 1.5% of purchase price
    const SALE_COMMISSION_PCT  = 0.05;        // 5% of ARV (buyer's + listing agent)
    const SALE_CLOSING_PCT     = 0.01;        // 1% seller closing costs
    const HOLDING_MONTHLY_PCT  = 0.008;       // 0.8% of total investment per month
    const DEFAULT_HOLD_MONTHS  = 6;           // Average flip timeline

    /** Minimum list price to exclude lease/land anomalies */
    const MIN_LIST_PRICE = 100000;

    /** ARV discount by road type — busy road comps overstate value */
    const ROAD_ARV_DISCOUNT = [
        'busy-road'        => 0.15,  // 15% discount
        'highway-adjacent' => 0.25,  // 25% discount
    ];

    /**
     * Run the full analysis pipeline (Pass 1: data-only scoring).
     *
     * @param array $options {
     *     limit: int (max properties to analyze),
     *     city: string|null (comma-separated city filter, overrides configured cities),
     * }
     * @param callable|null $progress Callback for progress updates: fn(string $message)
     * @return array { analyzed: int, disqualified: int, run_date: string }
     */
    public static function run(array $options = [], ?callable $progress = null): array {
        $limit = $options['limit'] ?? 500;
        $cities = !empty($options['city'])
            ? array_map('trim', explode(',', $options['city']))
            : Flip_Database::get_target_cities();

        if (empty($cities)) {
            return ['analyzed' => 0, 'disqualified' => 0, 'run_date' => '', 'error' => 'No target cities configured.'];
        }

        $log = $progress ?? function ($msg) {};
        $run_date = current_time('mysql');

        // Step 1: Pre-compute city-level metrics
        $log("Pre-computing metrics for " . count($cities) . " cities...");
        Flip_Location_Scorer::precompute_city_metrics($cities);

        // Step 2: Fetch active SFR listings in target cities
        $properties = self::fetch_properties($cities, $limit);
        $log("Found " . count($properties) . " Residential SFR listings in target cities.");

        if (empty($properties)) {
            return ['analyzed' => 0, 'disqualified' => 0, 'run_date' => $run_date];
        }

        $analyzed = 0;
        $disqualified = 0;

        // Step 3: Score each property
        foreach ($properties as $i => $property) {
            $listing_id = (int) $property->listing_id;
            $city = $property->city;

            if (($i + 1) % 10 === 0 || $i === 0) {
                $log("Analyzing property " . ($i + 1) . "/" . count($properties) . " (MLS# {$listing_id})...");
            }

            // Get city metrics
            $city_metrics = Flip_Location_Scorer::get_city_metrics($city);

            // Calculate ARV
            $arv_data = Flip_ARV_Calculator::calculate($property);

            // Check disqualifiers
            $disqualify = self::check_disqualifiers($property, $arv_data);
            if ($disqualify) {
                self::store_disqualified($property, $arv_data, $disqualify, $run_date);
                $disqualified++;
                $analyzed++;
                continue;
            }

            // Run all scorers
            $financial = Flip_Financial_Scorer::score($property, $arv_data, $city_metrics['avg_ppsf']);
            $prop_score = Flip_Property_Scorer::score($property);

            $comp_density = Flip_ARV_Calculator::count_nearby_comps(
                (float) $property->latitude,
                (float) $property->longitude
            );

            // Get road type from OSM (accurate, free)
            $street_name = trim(($property->street_name ?? '') . ' ' . ($property->street_suffix ?? ''));
            $road_analysis = Flip_Road_Analyzer::analyze(
                (float) $property->latitude,
                (float) $property->longitude,
                $street_name
            );

            // Pass road analysis to location scorer
            $location = Flip_Location_Scorer::score(
                $property,
                $city_metrics['price_trend'],
                $city_metrics['avg_dom'],
                $comp_density,
                $arv_data,
                ['road_type' => $road_analysis['road_type']]  // Pass road type from OSM
            );

            $remarks = Flip_Market_Scorer::get_remarks($listing_id);
            $market = Flip_Market_Scorer::score($property, $remarks);

            // Compute weighted total
            $total_score = ($financial['score'] * self::WEIGHT_FINANCIAL)
                         + ($prop_score['score'] * self::WEIGHT_PROPERTY)
                         + ($location['score'] * self::WEIGHT_LOCATION)
                         + ($market['score'] * self::WEIGHT_MARKET);

            // Financial calculations
            $sqft = (int) $property->building_area_total;
            $year_built = (int) $property->year_built;
            $rehab_per_sqft = self::get_rehab_per_sqft($year_built);
            $rehab_cost = $sqft * $rehab_per_sqft;
            $raw_arv = (float) $arv_data['estimated_arv'];
            $list_price = (float) $property->list_price;

            // Apply road type discount — comps on quiet streets overstate ARV for busy road properties
            $road_discount = self::ROAD_ARV_DISCOUNT[$road_analysis['road_type']] ?? 0;
            $arv = $road_discount > 0 ? round($raw_arv * (1 - $road_discount), 2) : $raw_arv;

            // Broken-down costs
            $purchase_closing = $list_price * self::PURCHASE_CLOSING_PCT;
            $sale_costs = $arv * (self::SALE_COMMISSION_PCT + self::SALE_CLOSING_PCT);
            $holding_costs = ($list_price + $rehab_cost) * self::HOLDING_MONTHLY_PCT * self::DEFAULT_HOLD_MONTHS;

            $mao = ($arv * 0.70) - $rehab_cost;
            $profit = $arv - $list_price - $rehab_cost - $purchase_closing - $sale_costs - $holding_costs;
            $total_investment = $list_price + $rehab_cost + $purchase_closing + $holding_costs;
            $roi = $total_investment > 0 ? ($profit / $total_investment) * 100 : 0;

            // Determine rehab level from age-based estimate
            $rehab_level = match (true) {
                $rehab_per_sqft <= 20 => 'cosmetic',
                $rehab_per_sqft <= 35 => 'moderate',
                $rehab_per_sqft <= 50 => 'significant',
                default               => 'major',
            };

            // Build comp details for storage
            $comp_details = array_map(function ($comp) {
                return [
                    'listing_id'    => $comp->listing_id,
                    'address'       => $comp->address ?? '',
                    'city'          => $comp->city ?? '',
                    'close_price'   => (float) $comp->close_price,
                    'sqft'          => (int) $comp->sqft,
                    'ppsf'          => round((float) $comp->ppsf, 2),
                    'close_date'    => $comp->close_date,
                    'distance_miles' => round((float) $comp->distance_miles, 2),
                    'bedrooms'      => (int) $comp->bedrooms_total,
                    'bathrooms'     => (float) $comp->bathrooms_total,
                    'dom'           => (int) ($comp->days_on_market ?? 0),
                ];
            }, $arv_data['comps']);

            // Store result
            $data = [
                'listing_id'           => $listing_id,
                'listing_key'          => $property->listing_key ?? '',
                'run_date'             => $run_date,
                'total_score'          => round($total_score, 2),
                'financial_score'      => $financial['score'],
                'property_score'       => $prop_score['score'],
                'location_score'       => $location['score'],
                'market_score'         => $market['score'],
                'estimated_arv'        => round($arv, 2),
                'arv_confidence'       => $arv_data['arv_confidence'],
                'comp_count'           => $arv_data['comp_count'],
                'avg_comp_ppsf'        => $arv_data['avg_comp_ppsf'],
                'comp_details_json'    => json_encode($comp_details),
                'neighborhood_ceiling' => round($arv_data['neighborhood_ceiling'] ?? 0, 2),
                'ceiling_pct'          => $arv_data['neighborhood_ceiling'] > 0 ? round(($arv / $arv_data['neighborhood_ceiling']) * 100, 1) : 0,
                'ceiling_warning'      => $arv_data['neighborhood_ceiling'] > 0 && ($arv / $arv_data['neighborhood_ceiling']) > 0.9 ? 1 : 0,
                'estimated_rehab_cost' => round($rehab_cost, 2),
                'rehab_level'          => $rehab_level,
                'mao'                  => round($mao, 2),
                'estimated_profit'     => round($profit, 2),
                'estimated_roi'        => round($roi, 2),
                'road_type'            => $road_analysis['road_type'],
                'days_on_market'       => (int) ($property->days_on_market ?? 0),
                'list_price'           => $list_price,
                'original_list_price'  => (float) ($property->original_list_price ?? 0),
                'price_per_sqft'       => (float) ($property->price_per_sqft ?? 0),
                'building_area_total'  => $sqft,
                'bedrooms_total'       => (int) $property->bedrooms_total,
                'bathrooms_total'      => (float) $property->bathrooms_total,
                'year_built'           => (int) $property->year_built,
                'lot_size_acres'       => (float) ($property->lot_size_acres ?? 0),
                'city'                 => $city,
                'address'              => trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '')),
                'main_photo_url'       => $property->main_photo_url ?? '',
                'remarks_signals_json' => json_encode($market['remarks_signals']),
                'disqualified'         => 0,
                'disqualify_reason'    => null,
            ];

            Flip_Database::upsert_result($data);
            $analyzed++;
        }

        $log("Analysis complete: {$analyzed} properties analyzed, {$disqualified} disqualified.");
        return [
            'analyzed'     => $analyzed,
            'disqualified' => $disqualified,
            'run_date'     => $run_date,
        ];
    }

    /**
     * Fetch active SFR listings from target cities.
     */
    private static function fetch_properties(array $cities, int $limit): array {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $placeholders = implode(',', array_fill(0, count($cities), '%s'));
        $params = $cities;
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$summary_table}
            WHERE property_type = 'Residential'
              AND property_sub_type = 'Single Family Residence'
              AND standard_status = 'Active'
              AND list_price > 0
              AND building_area_total > 0
              AND city IN ({$placeholders})
            ORDER BY list_price ASC
            LIMIT %d",
            $params
        ));
    }

    /**
     * Check auto-disqualifiers.
     *
     * @return string|null Reason for disqualification, or null if passed.
     */
    private static function check_disqualifiers(object $property, array $arv_data): ?string {
        $list_price = (float) $property->list_price;
        $arv = (float) $arv_data['estimated_arv'];
        $sqft = (int) $property->building_area_total;

        // Price too low (likely lease or land listing miscategorized)
        if ($list_price < self::MIN_LIST_PRICE) {
            return 'List price below minimum ($' . number_format(self::MIN_LIST_PRICE) . ') - likely lease or land';
        }

        // No comps found at all
        if ($arv_data['comp_count'] === 0) {
            return 'No comparable sales found within 1 mile';
        }

        // Building too small (likely data error)
        if ($sqft < 600) {
            return 'Building area too small (' . $sqft . ' sqft)';
        }

        // No margin even with optimistic estimate
        if ($arv > 0 && $list_price > $arv * 0.85) {
            return 'List price too close to ARV (ratio: ' . round($list_price / $arv, 2) . ')';
        }

        // Estimated rehab too expensive relative to ARV
        $year_built = (int) $property->year_built;
        $estimated_rehab = $sqft * self::get_rehab_per_sqft($year_built);
        if ($arv > 0 && $estimated_rehab > $arv * 0.35) {
            return 'Default rehab estimate exceeds 35% of ARV';
        }

        // ARV exceeds neighborhood ceiling by too much (unrealistic exit price)
        $ceiling_pct = $arv_data['ceiling_pct'] ?? 0;
        if ($ceiling_pct > 120) {
            return 'ARV exceeds 120% of neighborhood ceiling ($'
                . number_format($arv_data['neighborhood_ceiling'] ?? 0)
                . ', ceiling_pct: ' . round($ceiling_pct) . '%)';
        }

        return null;
    }

    /**
     * Store a disqualified property result.
     */
    private static function store_disqualified(object $property, array $arv_data, string $reason, string $run_date): void {
        $data = [
            'listing_id'          => (int) $property->listing_id,
            'listing_key'         => $property->listing_key ?? '',
            'run_date'            => $run_date,
            'total_score'         => 0,
            'financial_score'     => 0,
            'property_score'      => 0,
            'location_score'      => 0,
            'market_score'        => 0,
            'estimated_arv'       => round((float) $arv_data['estimated_arv'], 2),
            'arv_confidence'      => $arv_data['arv_confidence'],
            'comp_count'          => $arv_data['comp_count'],
            'avg_comp_ppsf'       => $arv_data['avg_comp_ppsf'],
            'neighborhood_ceiling' => round((float) ($arv_data['neighborhood_ceiling'] ?? 0), 2),
            'ceiling_pct'          => $arv_data['ceiling_pct'] ?? 0,
            'ceiling_warning'      => ($arv_data['ceiling_warning'] ?? false) ? 1 : 0,
            'estimated_rehab_cost' => 0,
            'rehab_level'         => 'unknown',
            'mao'                 => 0,
            'estimated_profit'    => 0,
            'estimated_roi'       => 0,
            'days_on_market'      => (int) ($property->days_on_market ?? 0),
            'list_price'          => (float) $property->list_price,
            'original_list_price' => (float) ($property->original_list_price ?? 0),
            'price_per_sqft'      => (float) ($property->price_per_sqft ?? 0),
            'building_area_total' => (int) $property->building_area_total,
            'bedrooms_total'      => (int) $property->bedrooms_total,
            'bathrooms_total'     => (float) $property->bathrooms_total,
            'year_built'          => (int) $property->year_built,
            'lot_size_acres'      => (float) ($property->lot_size_acres ?? 0),
            'city'                => $property->city ?? '',
            'address'             => trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '')),
            'main_photo_url'      => $property->main_photo_url ?? '',
            'disqualified'        => 1,
            'disqualify_reason'   => $reason,
        ];

        Flip_Database::upsert_result($data);
    }

    /**
     * Get rehab cost per sqft based on property age.
     * Older homes need more work: electrical, plumbing, insulation, etc.
     */
    private static function get_rehab_per_sqft(int $year_built): int {
        if ($year_built <= 0) return 45; // Unknown age, assume significant
        $age = (int) date('Y') - $year_built;

        if ($age <= 10)  return 15;  // Cosmetic: paint, staging, minor updates
        if ($age <= 25)  return 30;  // Moderate: kitchen/bath refresh, flooring
        if ($age <= 50)  return 45;  // Significant: kitchen/bath gut, systems updates
        return 60;                    // Major: everything + possible structural
    }
}
