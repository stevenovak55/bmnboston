<?php
/**
 * Flip Analyzer Orchestrator.
 *
 * Coordinates the analysis pipeline: fetches properties, runs all scorers,
 * checks disqualifiers, computes weighted totals, and stores results.
 *
 * v0.6.0: Dual financial model (cash + financed), remarks-based rehab,
 * dynamic hold period, contingency, financing costs, min profit gate.
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

    /** Transaction cost constants */
    const PURCHASE_CLOSING_PCT = 0.015;       // 1.5% of purchase price
    const SALE_COMMISSION_PCT  = 0.05;        // 5% of ARV (buyer's + listing agent)
    const SALE_CLOSING_PCT     = 0.01;        // 1% seller closing costs

    /** Financing constants (hard money loan) */
    const HARD_MONEY_RATE      = 0.12;        // 12% annual interest rate
    const HARD_MONEY_POINTS    = 0.02;        // 2 origination points
    const HARD_MONEY_LTV       = 0.80;        // 80% loan-to-value

    /** Rehab & holding constants */
    const CONTINGENCY_PCT      = 0.10;        // 10% rehab contingency
    const ANNUAL_TAX_RATE      = 0.013;       // 1.3% of purchase price (MA average)
    const ANNUAL_INSURANCE_RATE = 0.005;      // 0.5% builder's risk insurance
    const MONTHLY_UTILITIES    = 350;         // Electric, water, heat during rehab

    /** Profit thresholds (using financed numbers) */
    const MIN_PROFIT_THRESHOLD = 25000;       // $25K minimum
    const MIN_ROI_THRESHOLD    = 15;          // 15% minimum

    /** Minimum list price to exclude lease/land anomalies */
    const MIN_LIST_PRICE = 100000;

    /** ARV discount by road type */
    const ROAD_ARV_DISCOUNT = [
        'busy-road'        => 0.15,
        'highway-adjacent' => 0.25,
    ];

    /**
     * Calculate all financial metrics for a property.
     *
     * Shared by the main pipeline and photo analyzer to avoid formula divergence.
     *
     * @param float  $arv            After Repair Value.
     * @param float  $list_price     Current asking price.
     * @param int    $sqft           Building square footage.
     * @param int    $year_built     Year the property was built.
     * @param string|null $remarks   Public remarks for rehab signal analysis.
     * @param float  $area_avg_dom   City average days on market (for hold estimate).
     * @return array Financial breakdown with cash and financed scenarios.
     */
    public static function calculate_financials(
        float $arv,
        float $list_price,
        int $sqft,
        int $year_built,
        ?string $remarks = null,
        float $area_avg_dom = 30
    ): array {
        // Rehab with remarks adjustment + contingency
        $rehab_per_sqft  = self::get_rehab_per_sqft($year_built);
        $rehab_multiplier = self::get_remarks_rehab_multiplier($remarks);
        $base_rehab       = $sqft * $rehab_per_sqft * $rehab_multiplier;
        $rehab_contingency = $base_rehab * self::CONTINGENCY_PCT;
        $rehab_cost       = round($base_rehab + $rehab_contingency, 2);

        // Dynamic hold period
        $hold_months = self::estimate_hold_months($rehab_per_sqft, $area_avg_dom);

        // Rehab level
        $effective_per_sqft = $rehab_per_sqft * $rehab_multiplier;
        $rehab_level = match (true) {
            $effective_per_sqft <= 20 => 'cosmetic',
            $effective_per_sqft <= 35 => 'moderate',
            $effective_per_sqft <= 50 => 'significant',
            default                   => 'major',
        };

        // Transaction costs
        $purchase_closing = $list_price * self::PURCHASE_CLOSING_PCT;
        $sale_costs = $arv * (self::SALE_COMMISSION_PCT + self::SALE_CLOSING_PCT);

        // Holding costs (taxes + insurance + utilities)
        $monthly_taxes     = ($list_price * self::ANNUAL_TAX_RATE) / 12;
        $monthly_insurance = ($list_price * self::ANNUAL_INSURANCE_RATE) / 12;
        $monthly_holding   = $monthly_taxes + $monthly_insurance + self::MONTHLY_UTILITIES;
        $holding_costs     = round($monthly_holding * $hold_months, 2);

        // === Cash Scenario ===
        $cash_profit = $arv - $list_price - $rehab_cost - $purchase_closing - $sale_costs - $holding_costs;
        $cash_investment = $list_price + $rehab_cost + $purchase_closing + $holding_costs;
        $cash_roi = $cash_investment > 0 ? ($cash_profit / $cash_investment) * 100 : 0;

        // === Financed Scenario (hard money) ===
        $loan_amount     = $list_price * self::HARD_MONEY_LTV;
        $origination_fee = $loan_amount * self::HARD_MONEY_POINTS;
        $monthly_interest = $loan_amount * (self::HARD_MONEY_RATE / 12);
        $total_interest  = $monthly_interest * $hold_months;
        $financing_costs = round($origination_fee + $total_interest, 2);

        $financed_profit = $cash_profit - $financing_costs;
        $cash_invested   = ($list_price * (1 - self::HARD_MONEY_LTV)) + $rehab_cost + $purchase_closing;
        $cash_on_cash_roi = $cash_invested > 0 ? ($financed_profit / $cash_invested) * 100 : 0;

        // MAO uses 70% rule
        $mao = ($arv * 0.70) - $rehab_cost;

        return [
            'rehab_per_sqft'    => $rehab_per_sqft,
            'rehab_multiplier'  => round($rehab_multiplier, 2),
            'rehab_cost'        => $rehab_cost,
            'rehab_contingency' => round($rehab_contingency, 2),
            'rehab_level'       => $rehab_level,
            'hold_months'       => $hold_months,
            'purchase_closing'  => round($purchase_closing, 2),
            'sale_costs'        => round($sale_costs, 2),
            'holding_costs'     => $holding_costs,
            'financing_costs'   => $financing_costs,
            'mao'               => round($mao, 2),
            // Cash scenario
            'cash_profit'       => round($cash_profit, 2),
            'cash_roi'          => round($cash_roi, 2),
            // Financed scenario (primary — used for scoring/disqualification)
            'estimated_profit'  => round($financed_profit, 2),
            'estimated_roi'     => round($cash_on_cash_roi, 2),
            'cash_on_cash_roi'  => round($cash_on_cash_roi, 2),
        ];
    }

    /**
     * Run the full analysis pipeline (Pass 1: data-only scoring).
     *
     * @param array $options { limit: int, city: string|null }
     * @param callable|null $progress Callback: fn(string $message)
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

            // Check pre-financial disqualifiers
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

            // Road type from OSM
            $street_name = trim(($property->street_name ?? '') . ' ' . ($property->street_suffix ?? ''));
            $road_analysis = Flip_Road_Analyzer::analyze(
                (float) $property->latitude,
                (float) $property->longitude,
                $street_name
            );

            $location = Flip_Location_Scorer::score(
                $property,
                $city_metrics['price_trend'],
                $city_metrics['avg_dom'],
                $comp_density,
                $arv_data,
                ['road_type' => $road_analysis['road_type']]
            );

            $remarks = Flip_Market_Scorer::get_remarks($listing_id);
            $market = Flip_Market_Scorer::score($property, $remarks);

            // Compute weighted total
            $total_score = ($financial['score'] * self::WEIGHT_FINANCIAL)
                         + ($prop_score['score'] * self::WEIGHT_PROPERTY)
                         + ($location['score'] * self::WEIGHT_LOCATION)
                         + ($market['score'] * self::WEIGHT_MARKET);

            // Financial calculations via shared method
            $sqft = (int) $property->building_area_total;
            $year_built = (int) $property->year_built;
            $list_price = (float) $property->list_price;
            $raw_arv = (float) $arv_data['estimated_arv'];

            // Apply road type discount
            $road_discount = self::ROAD_ARV_DISCOUNT[$road_analysis['road_type']] ?? 0;
            $arv = $road_discount > 0 ? round($raw_arv * (1 - $road_discount), 2) : $raw_arv;

            $fin = self::calculate_financials(
                $arv, $list_price, $sqft, $year_built,
                $remarks, $city_metrics['avg_dom'] ?? 30
            );

            // Post-calculation disqualifier (uses financed numbers)
            $post_dq = self::check_post_calc_disqualifiers($fin['estimated_profit'], $fin['cash_on_cash_roi']);
            if ($post_dq) {
                // Still store full data so dashboard shows why it was DQ'd
                $data = self::build_result_data(
                    $property, $arv_data, $road_analysis, $fin,
                    $total_score, $financial, $prop_score, $location, $market,
                    $arv, $run_date
                );
                $data['disqualified'] = 1;
                $data['disqualify_reason'] = $post_dq;
                Flip_Database::upsert_result($data);
                $disqualified++;
                $analyzed++;
                continue;
            }

            // Store result
            $data = self::build_result_data(
                $property, $arv_data, $road_analysis, $fin,
                $total_score, $financial, $prop_score, $location, $market,
                $arv, $run_date
            );
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
     * Build the result data array for database storage.
     */
    private static function build_result_data(
        object $property, array $arv_data, array $road_analysis, array $fin,
        float $total_score, array $financial, array $prop_score, array $location, array $market,
        float $arv, string $run_date
    ): array {
        $listing_id = (int) $property->listing_id;

        // Build comp details with adjustments
        $comp_details = array_map(function ($comp) {
            return [
                'listing_id'       => $comp->listing_id,
                'address'          => $comp->address ?? '',
                'city'             => $comp->city ?? '',
                'close_price'      => (float) $comp->close_price,
                'adjusted_price'   => (float) ($comp->adjusted_price ?? $comp->close_price),
                'sqft'             => (int) $comp->sqft,
                'ppsf'             => round((float) $comp->ppsf, 2),
                'adjusted_ppsf'    => round((float) ($comp->adjusted_ppsf ?? $comp->ppsf), 2),
                'close_date'       => $comp->close_date,
                'distance_miles'   => round((float) $comp->distance_miles, 2),
                'bedrooms'         => (int) $comp->bedrooms_total,
                'bathrooms'        => (float) $comp->bathrooms_total,
                'garage_spaces'    => (int) ($comp->garage_spaces ?? 0),
                'has_basement'     => (int) ($comp->has_basement ?? 0),
                'dom'              => (int) ($comp->days_on_market ?? 0),
                'reno_priority'    => (int) ($comp->reno_priority ?? 0),
                'adjustments'      => $comp->adjustments ?? [],
                'total_adjustment' => round((float) ($comp->total_adjustment ?? 0), 2),
                'sale_to_list'     => !empty($comp->comp_list_price) && (float) $comp->comp_list_price > 0
                    ? round((float) $comp->close_price / (float) $comp->comp_list_price, 3) : null,
            ];
        }, $arv_data['comps']);

        return [
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
            'ceiling_pct'          => $arv_data['neighborhood_ceiling'] > 0
                ? round(($arv / $arv_data['neighborhood_ceiling']) * 100, 1) : 0,
            'ceiling_warning'      => $arv_data['neighborhood_ceiling'] > 0
                && ($arv / $arv_data['neighborhood_ceiling']) > 0.9 ? 1 : 0,
            'estimated_rehab_cost' => $fin['rehab_cost'],
            'rehab_level'          => $fin['rehab_level'],
            'mao'                  => $fin['mao'],
            'estimated_profit'     => $fin['estimated_profit'],
            'estimated_roi'        => $fin['estimated_roi'],
            'financing_costs'      => $fin['financing_costs'],
            'holding_costs'        => $fin['holding_costs'],
            'rehab_contingency'    => $fin['rehab_contingency'],
            'hold_months'          => $fin['hold_months'],
            'cash_profit'          => $fin['cash_profit'],
            'cash_roi'             => $fin['cash_roi'],
            'cash_on_cash_roi'     => $fin['cash_on_cash_roi'],
            'market_strength'      => $arv_data['market_strength'] ?? 'balanced',
            'avg_sale_to_list'     => $arv_data['avg_sale_to_list'] ?? 1.0,
            'rehab_multiplier'     => $fin['rehab_multiplier'],
            'road_type'            => $road_analysis['road_type'],
            'days_on_market'       => (int) ($property->days_on_market ?? 0),
            'list_price'           => (float) $property->list_price,
            'original_list_price'  => (float) ($property->original_list_price ?? 0),
            'price_per_sqft'       => (float) ($property->price_per_sqft ?? 0),
            'building_area_total'  => (int) $property->building_area_total,
            'bedrooms_total'       => (int) $property->bedrooms_total,
            'bathrooms_total'      => (float) $property->bathrooms_total,
            'year_built'           => (int) $property->year_built,
            'lot_size_acres'       => (float) ($property->lot_size_acres ?? 0),
            'city'                 => $property->city ?? '',
            'address'              => trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '')),
            'main_photo_url'       => $property->main_photo_url ?? '',
            'remarks_signals_json' => json_encode($market['remarks_signals']),
            'disqualified'         => 0,
            'disqualify_reason'    => null,
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
     * Check pre-financial auto-disqualifiers.
     *
     * @return string|null Reason for disqualification, or null if passed.
     */
    private static function check_disqualifiers(object $property, array $arv_data): ?string {
        $list_price = (float) $property->list_price;
        $arv = (float) $arv_data['estimated_arv'];
        $sqft = (int) $property->building_area_total;

        if ($list_price < self::MIN_LIST_PRICE) {
            return 'List price below minimum ($' . number_format(self::MIN_LIST_PRICE) . ') - likely lease or land';
        }

        if ($arv_data['comp_count'] === 0) {
            return 'No comparable sales found within 1 mile';
        }

        if ($sqft < 600) {
            return 'Building area too small (' . $sqft . ' sqft)';
        }

        if ($arv > 0 && $list_price > $arv * 0.85) {
            return 'List price too close to ARV (ratio: ' . round($list_price / $arv, 2) . ')';
        }

        $year_built = (int) $property->year_built;
        $estimated_rehab = $sqft * self::get_rehab_per_sqft($year_built);
        if ($arv > 0 && $estimated_rehab > $arv * 0.35) {
            return 'Default rehab estimate exceeds 35% of ARV';
        }

        $ceiling_pct = $arv_data['ceiling_pct'] ?? 0;
        if ($ceiling_pct > 120) {
            return 'ARV exceeds 120% of neighborhood ceiling ($'
                . number_format($arv_data['neighborhood_ceiling'] ?? 0)
                . ', ceiling_pct: ' . round($ceiling_pct) . '%)';
        }

        return null;
    }

    /**
     * Post-calculation disqualifier using financed numbers.
     *
     * @return string|null Reason for disqualification, or null if passed.
     */
    private static function check_post_calc_disqualifiers(float $profit, float $roi): ?string {
        if ($profit < self::MIN_PROFIT_THRESHOLD) {
            return 'Estimated profit ($' . number_format($profit) . ') below minimum ($'
                . number_format(self::MIN_PROFIT_THRESHOLD) . ')';
        }

        if ($roi < self::MIN_ROI_THRESHOLD) {
            return 'Estimated ROI (' . round($roi, 1) . '%) below minimum ('
                . self::MIN_ROI_THRESHOLD . '%)';
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
            'financing_costs'     => 0,
            'holding_costs'       => 0,
            'rehab_contingency'   => 0,
            'hold_months'         => 0,
            'cash_profit'         => 0,
            'cash_roi'            => 0,
            'cash_on_cash_roi'    => 0,
            'market_strength'     => $arv_data['market_strength'] ?? 'balanced',
            'avg_sale_to_list'    => $arv_data['avg_sale_to_list'] ?? 1.0,
            'rehab_multiplier'    => 1.0,
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
     */
    public static function get_rehab_per_sqft(int $year_built): int {
        if ($year_built <= 0) return 45;
        $age = (int) wp_date('Y') - $year_built;

        if ($age <= 10)  return 15;
        if ($age <= 25)  return 30;
        if ($age <= 50)  return 45;
        return 60;
    }

    /**
     * Adjust rehab estimate based on remarks keywords.
     *
     * Returns a multiplier (0.5 = half estimate, 1.5 = 50% more).
     */
    private static function get_remarks_rehab_multiplier(?string $remarks): float {
        if (empty($remarks)) return 1.0;
        $lower = strtolower($remarks);

        $cost_reducers = [
            'new roof'           => -0.08,
            'new furnace'        => -0.05,
            'new boiler'         => -0.05,
            'new hvac'           => -0.06,
            'updated electrical' => -0.06,
            'new plumbing'       => -0.05,
            'new windows'        => -0.06,
            'new siding'         => -0.04,
            'updated kitchen'    => -0.07,
            'updated bath'       => -0.05,
            'new kitchen'        => -0.10,
            'new bath'           => -0.07,
            'newer roof'         => -0.04,
            'newer windows'      => -0.03,
        ];

        $cost_increasers = [
            'needs work'           => 0.10,
            'needs updating'       => 0.10,
            'needs renovation'     => 0.15,
            'original kitchen'     => 0.07,
            'original bath'        => 0.05,
            'dated'                => 0.05,
            'deferred maintenance' => 0.15,
            'oil heat'             => 0.05,
            'knob and tube'        => 0.15,
            'lead paint'           => 0.05,
            'asbestos'             => 0.05,
            'foundation issues'    => 0.20,
            'water damage'         => 0.10,
        ];

        $adjustment = 0.0;
        foreach ($cost_reducers as $keyword => $delta) {
            if (str_contains($lower, $keyword)) {
                $adjustment += $delta;
            }
        }
        foreach ($cost_increasers as $keyword => $delta) {
            if (str_contains($lower, $keyword)) {
                $adjustment += $delta;
            }
        }

        return max(0.5, min(1.5, 1.0 + $adjustment));
    }

    /**
     * Estimate hold period based on rehab scope and local market velocity.
     */
    private static function estimate_hold_months(int $rehab_per_sqft, float $area_avg_dom = 30): int {
        // Rehab phase
        $rehab_months = match (true) {
            $rehab_per_sqft <= 20 => 1,
            $rehab_per_sqft <= 35 => 2,
            $rehab_per_sqft <= 50 => 4,
            default               => 6,
        };

        // Sale phase (based on area average DOM)
        $sale_months = max(1, (int) ceil($area_avg_dom / 30));

        // Permit buffer for larger renovations
        $permit_months = $rehab_per_sqft > 35 ? 1 : 0;

        return $rehab_months + $sale_months + $permit_months;
    }
}
