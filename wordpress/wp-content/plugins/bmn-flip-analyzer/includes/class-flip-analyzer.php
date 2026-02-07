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
    const SALE_COMMISSION_PCT  = 0.045;       // 4.5% of ARV (post-NAR settlement)
    const SALE_CLOSING_PCT     = 0.01;        // 1% seller closing costs
    const MA_TRANSFER_TAX_RATE = 0.00456;     // MA deed excise tax: $4.56 per $1,000

    /** Financing constants (hard money loan) */
    const HARD_MONEY_RATE      = 0.105;       // 10.5% annual interest rate (2025-2026 market)
    const HARD_MONEY_POINTS    = 0.02;        // 2 origination points
    const HARD_MONEY_LTV       = 0.80;        // 80% loan-to-value

    /** Rehab & holding constants */
    const ANNUAL_TAX_RATE      = 0.013;       // 1.3% of purchase price (MA average fallback)
    const LEAD_PAINT_ALLOWANCE = 8000;        // $8K flat for pre-1978 properties
    const LEAD_PAINT_YEAR      = 1978;
    const ANNUAL_INSURANCE_RATE = 0.005;      // 0.5% builder's risk insurance
    const MONTHLY_UTILITIES    = 350;         // Electric, water, heat during rehab

    /** Profit thresholds — baseline values (used in balanced markets) */
    const MIN_PROFIT_THRESHOLD = 25000;       // $25K minimum
    const MIN_ROI_THRESHOLD    = 15;          // 15% minimum

    /** Market-adaptive threshold guard rails: [floor, ceiling] per tier */
    const MARKET_THRESHOLD_BOUNDS = [
        'very_hot' => ['min_profit' => [10000, 20000], 'min_roi' => [5, 8]],
        'hot'      => ['min_profit' => [15000, 25000], 'min_roi' => [8, 12]],
        'balanced' => ['min_profit' => [25000, 25000], 'min_roi' => [15, 15]],
        'soft'     => ['min_profit' => [25000, 30000], 'min_roi' => [15, 18]],
        'cold'     => ['min_profit' => [28000, 35000], 'min_roi' => [16, 22]],
    ];

    /** Market-adaptive max list_price / ARV ratio for pre-calc DQ */
    const MARKET_MAX_PRICE_ARV_RATIO = [
        'very_hot' => 0.92, 'hot' => 0.90, 'balanced' => 0.85,
        'soft' => 0.82, 'cold' => 0.78,
    ];

    /** Minimum list price to exclude lease/land anomalies */
    const MIN_LIST_PRICE = 100000;

    /** ARV discount by road type */
    const ROAD_ARV_DISCOUNT = [
        'busy-road'        => 0.15,
        'highway-adjacent' => 0.25,
    ];

    /**
     * Calculate market-adaptive thresholds for profit and ROI.
     *
     * Uses a continuous formula based on avg_sale_to_list, clamped by
     * market_strength tier guard rails. Low-confidence ARV prevents
     * thresholds from relaxing below balanced levels.
     *
     * @param string $market_strength  Market tier (very_hot, hot, balanced, soft, cold).
     * @param float  $avg_sale_to_list Sale-to-list ratio (e.g. 1.07).
     * @param string $arv_confidence   ARV confidence level (high, medium, low, none).
     * @return array Adaptive thresholds with min_profit, min_roi, max_price_arv.
     */
    public static function get_adaptive_thresholds(
        string $market_strength,
        float $avg_sale_to_list,
        string $arv_confidence = 'medium'
    ): array {
        // Continuous multiplier: 1.0 at STL=1.00, lower for hot, higher for cold
        $multiplier = max(0.4, min(1.2, 2.5 - (1.5 * $avg_sale_to_list)));

        // Raw scaled thresholds from baseline
        $raw_profit = self::MIN_PROFIT_THRESHOLD * $multiplier;
        $raw_roi    = self::MIN_ROI_THRESHOLD * $multiplier;

        // Determine tier bounds — low-confidence ARV uses balanced (no relaxation)
        $use_tier = $market_strength;
        if (in_array($arv_confidence, ['low', 'none'], true)) {
            // Don't relax below balanced when ARV data is weak
            if (in_array($market_strength, ['very_hot', 'hot'], true)) {
                $use_tier = 'balanced';
            }
        }

        $bounds = self::MARKET_THRESHOLD_BOUNDS[$use_tier] ?? self::MARKET_THRESHOLD_BOUNDS['balanced'];
        $min_profit = max($bounds['min_profit'][0], min($bounds['min_profit'][1], $raw_profit));
        $min_roi    = max($bounds['min_roi'][0], min($bounds['min_roi'][1], $raw_roi));

        $max_price_arv = self::MARKET_MAX_PRICE_ARV_RATIO[$market_strength]
            ?? self::MARKET_MAX_PRICE_ARV_RATIO['balanced'];

        return [
            'min_profit'      => round($min_profit),
            'min_roi'         => round($min_roi, 1),
            'max_price_arv'   => $max_price_arv,
            'market_strength' => $market_strength,
            'avg_sale_to_list' => round($avg_sale_to_list, 3),
            'multiplier'      => round($multiplier, 3),
        ];
    }

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
     * @param float|null $actual_tax_rate Actual property tax rate from MLS data (null = use MA average).
     * @return array Financial breakdown with cash and financed scenarios.
     */
    public static function calculate_financials(
        float $arv,
        float $list_price,
        int $sqft,
        int $year_built,
        ?string $remarks = null,
        float $area_avg_dom = 30,
        ?float $actual_tax_rate = null
    ): array {
        // Rehab with age condition + remarks adjustment
        $rehab_per_sqft     = self::get_rehab_per_sqft($year_built);
        $age_condition_mult = self::get_age_condition_multiplier($year_built);
        $rehab_multiplier   = self::get_remarks_rehab_multiplier($remarks);
        $effective_per_sqft = max(2.0, $rehab_per_sqft * $age_condition_mult * $rehab_multiplier);
        $base_rehab       = $sqft * $effective_per_sqft;

        // Lead paint allowance for pre-1978 (skip if remarks already mention it)
        $lead_paint_flag = ($year_built > 0 && $year_built < self::LEAD_PAINT_YEAR);
        $lead_paint_cost = 0;
        if ($lead_paint_flag) {
            $remarks_lower = strtolower($remarks ?? '');
            if (!str_contains($remarks_lower, 'lead paint') && !str_contains($remarks_lower, 'deleading')) {
                $lead_paint_cost = self::LEAD_PAINT_ALLOWANCE;
                $base_rehab += $lead_paint_cost;
            }
        }

        // Contingency rate based on actual scope of work (before age discount),
        // since the rate should reflect complexity/uncertainty, not the discounted cost
        $scope_per_sqft    = $rehab_per_sqft * $rehab_multiplier;
        $contingency_rate  = self::get_contingency_rate($scope_per_sqft);
        $rehab_contingency = $base_rehab * $contingency_rate;
        $rehab_cost        = round($base_rehab + $rehab_contingency, 2);

        // Dynamic hold period uses scope-based per-sqft (age discount reduces cost, not timeline)
        $hold_months = self::estimate_hold_months($scope_per_sqft, $area_avg_dom);

        // Rehab level
        $rehab_level = match (true) {
            $effective_per_sqft <= 20 => 'cosmetic',
            $effective_per_sqft <= 35 => 'moderate',
            $effective_per_sqft <= 50 => 'significant',
            default                   => 'major',
        };

        // MA transfer tax (deed excise tax) on both buy and sell
        $transfer_tax_buy  = $list_price * self::MA_TRANSFER_TAX_RATE;
        $transfer_tax_sell = $arv * self::MA_TRANSFER_TAX_RATE;

        // Transaction costs (including transfer tax)
        $purchase_closing = ($list_price * self::PURCHASE_CLOSING_PCT) + $transfer_tax_buy;
        $sale_costs = $arv * (self::SALE_COMMISSION_PCT + self::SALE_CLOSING_PCT) + $transfer_tax_sell;

        // Holding costs (taxes + insurance + utilities)
        $tax_rate = $actual_tax_rate ?? self::ANNUAL_TAX_RATE;
        $monthly_taxes     = ($list_price * $tax_rate) / 12;
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

        // MAO: classic 70% rule + adjusted (includes holding + financing)
        $mao = ($arv * 0.70) - $rehab_cost;
        $adjusted_mao = ($arv * 0.70) - $rehab_cost - $holding_costs - $financing_costs;

        // Breakeven ARV (minimum ARV for $0 financed profit)
        $sale_cost_pct = self::SALE_COMMISSION_PCT + self::SALE_CLOSING_PCT + self::MA_TRANSFER_TAX_RATE;
        $breakeven_arv = ($list_price + $rehab_cost + $purchase_closing + $holding_costs + $financing_costs)
                         / (1 - $sale_cost_pct);

        // Annualized ROI (for comparing deals with different hold periods)
        $annualized_roi = 0;
        if ($hold_months > 0 && $cash_on_cash_roi > -100) {
            $annualized_roi = (pow(1 + $cash_on_cash_roi / 100, 12 / $hold_months) - 1) * 100;
        }

        return [
            'rehab_per_sqft'          => $rehab_per_sqft,
            'age_condition_multiplier' => round($age_condition_mult, 2),
            'rehab_multiplier'        => round($rehab_multiplier, 2),
            'rehab_cost'        => $rehab_cost,
            'rehab_contingency' => round($rehab_contingency, 2),
            'contingency_rate'  => $contingency_rate,
            'rehab_level'       => $rehab_level,
            'hold_months'       => $hold_months,
            'purchase_closing'  => round($purchase_closing, 2),
            'sale_costs'        => round($sale_costs, 2),
            'holding_costs'     => $holding_costs,
            'financing_costs'   => $financing_costs,
            'transfer_tax_buy'  => round($transfer_tax_buy, 2),
            'transfer_tax_sell' => round($transfer_tax_sell, 2),
            'lead_paint_flag'   => $lead_paint_flag,
            'lead_paint_cost'   => $lead_paint_cost,
            'mao'               => round($mao, 2),
            'adjusted_mao'      => round($adjusted_mao, 2),
            'breakeven_arv'     => round($breakeven_arv, 2),
            'annualized_roi'    => round($annualized_roi, 2),
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
     * @param array $options { limit, city, filters, report_id, listing_ids }
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

        // Load analysis filters (CLI overrides or saved settings)
        $filters = $options['filters'] ?? Flip_Database::get_analysis_filters();
        $report_id = $options['report_id'] ?? null;

        $log = $progress ?? function ($msg) {};
        $run_date = current_time('mysql');

        // Step 1: Pre-compute city-level metrics
        $log("Pre-computing metrics for " . count($cities) . " cities...");
        Flip_Location_Scorer::precompute_city_metrics($cities);

        // Step 2: Fetch listings matching filters (or specific IDs for monitors)
        if (!empty($options['listing_ids'])) {
            $properties = self::fetch_properties_by_ids($options['listing_ids']);
        } else {
            $properties = self::fetch_properties($cities, $limit, $filters);
        }
        $sub_types = implode(', ', $filters['property_sub_types'] ?? ['SFR']);
        $statuses  = implode(', ', $filters['statuses'] ?? ['Active']);
        $log("Found " . count($properties) . " Residential ({$sub_types}) [{$statuses}] listings in target cities.");

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

            // Fetch remarks early (needed for DQ distress check and later for market scoring + financials)
            $remarks = Flip_Market_Scorer::get_remarks($listing_id);
            $property_condition = self::get_property_condition($listing_id);

            // Calculate ARV
            $arv_data = Flip_ARV_Calculator::calculate($property);

            // Check pre-financial disqualifiers (includes new construction check)
            $disqualify = self::check_disqualifiers($property, $arv_data, $remarks, $property_condition);
            if ($disqualify) {
                self::store_disqualified($property, $arv_data, $disqualify, $run_date, $report_id);
                $disqualified++;
                $analyzed++;
                continue;
            }

            // Run all scorers
            $financial = Flip_Financial_Scorer::score($property, $arv_data, $city_metrics['avg_ppsf']);
            $prop_score = Flip_Property_Scorer::score($property);

            $sub_type = $property->property_sub_type ?? 'Single Family Residence';
            $comp_density = Flip_ARV_Calculator::count_nearby_comps(
                (float) $property->latitude,
                (float) $property->longitude,
                0.5, 6, $sub_type
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

            // $remarks already fetched earlier (before DQ check)
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

            // Look up actual property tax rate from MLS financial data
            $actual_tax_rate = self::get_actual_tax_rate($listing_id, $list_price);

            $fin = self::calculate_financials(
                $arv, $list_price, $sqft, $year_built,
                $remarks, $city_metrics['avg_dom'] ?? 30,
                $actual_tax_rate
            );

            // Compute market-adaptive thresholds
            $thresholds = self::get_adaptive_thresholds(
                $arv_data['market_strength'] ?? 'balanced',
                $arv_data['avg_sale_to_list'] ?? 1.0,
                $arv_data['arv_confidence'] ?? 'medium'
            );
            $thresholds_json = json_encode($thresholds);

            // Deal risk grade
            $deal_risk_grade = self::calculate_deal_risk_grade(
                $arv_data['arv_confidence'] ?? 'none',
                $fin['breakeven_arv'],
                $arv,
                $arv_data['comps'],
                (int) ($property->days_on_market ?? 0),
                $arv_data['comp_count']
            );

            // Post-calculation disqualifier (uses financed numbers + adaptive thresholds)
            $post_dq = self::check_post_calc_disqualifiers(
                $fin['estimated_profit'], $fin['cash_on_cash_roi'], $thresholds,
                $arv_data['arv_confidence'] ?? 'medium'
            );
            if ($post_dq) {
                // Check if near-viable (within 80% of adjusted thresholds)
                $near_viable = ($fin['estimated_profit'] >= $thresholds['min_profit'] * 0.8)
                            && ($fin['cash_on_cash_roi'] >= $thresholds['min_roi'] * 0.8);

                // Still store full data so dashboard shows why it was DQ'd
                $data = self::build_result_data(
                    $property, $arv_data, $road_analysis, $fin,
                    $total_score, $financial, $prop_score, $location, $market,
                    $arv, $run_date
                );
                $data['disqualified'] = 1;
                $data['disqualify_reason'] = $post_dq;
                $data['near_viable'] = $near_viable ? 1 : 0;
                $data['applied_thresholds_json'] = $thresholds_json;
                $data['deal_risk_grade'] = $deal_risk_grade;
                if ($report_id) {
                    $data['report_id'] = $report_id;
                }
                Flip_Database::upsert_result($data);
                $disqualified++;
                $analyzed++;
                continue;
            }

            // Store viable result
            $data = self::build_result_data(
                $property, $arv_data, $road_analysis, $fin,
                $total_score, $financial, $prop_score, $location, $market,
                $arv, $run_date
            );
            $data['applied_thresholds_json'] = $thresholds_json;
            $data['deal_risk_grade'] = $deal_risk_grade;
            if ($report_id) {
                $data['report_id'] = $report_id;
            }
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
            'rehab_multiplier'         => $fin['rehab_multiplier'],
            'age_condition_multiplier' => $fin['age_condition_multiplier'],
            'road_type'                => $road_analysis['road_type'],
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
            'annualized_roi'       => $fin['annualized_roi'],
            'breakeven_arv'        => $fin['breakeven_arv'],
            'lead_paint_flag'      => $fin['lead_paint_flag'] ? 1 : 0,
            'transfer_tax_buy'     => $fin['transfer_tax_buy'],
            'transfer_tax_sell'    => $fin['transfer_tax_sell'],
        ];
    }

    /**
     * Force full analysis on a single property, bypassing all DQ checks.
     *
     * @param int         $listing_id MLS listing ID.
     * @param string|null $run_date   Run date to use (defaults to latest existing run_date).
     * @param int|null    $report_id  Report to associate result with.
     * @return array { success: bool, listing_id: int, total_score: float } or { error: string }
     */
    public static function force_analyze_single(int $listing_id, ?string $run_date = null, ?int $report_id = null): array {
        global $wpdb;

        // 1. Fetch property
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id = %d",
            $listing_id
        ));
        if (!$property) {
            return ['error' => "Listing {$listing_id} not found in bme_listing_summary."];
        }

        // 2. Determine run_date (use latest batch so it groups on the dashboard)
        if (!$run_date) {
            $table = Flip_Database::table_name();
            $run_date = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
            if (!$run_date) {
                $run_date = current_time('mysql');
            }
        }

        // 3. Precompute city metrics
        Flip_Location_Scorer::precompute_city_metrics([$property->city]);
        $city_metrics = Flip_Location_Scorer::get_city_metrics($property->city);

        // 4. Calculate ARV
        $arv_data = Flip_ARV_Calculator::calculate($property);

        // 5. Run all scorers (NO DQ checks)
        $financial  = Flip_Financial_Scorer::score($property, $arv_data, $city_metrics['avg_ppsf']);
        $prop_score = Flip_Property_Scorer::score($property);

        $sub_type = $property->property_sub_type ?? 'Single Family Residence';
        $comp_density = Flip_ARV_Calculator::count_nearby_comps(
            (float) $property->latitude,
            (float) $property->longitude,
            0.5, 6, $sub_type
        );

        $street_name   = trim(($property->street_name ?? '') . ' ' . ($property->street_suffix ?? ''));
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
        $market  = Flip_Market_Scorer::score($property, $remarks);

        // 6. Weighted total
        $total_score = ($financial['score'] * self::WEIGHT_FINANCIAL)
                     + ($prop_score['score'] * self::WEIGHT_PROPERTY)
                     + ($location['score'] * self::WEIGHT_LOCATION)
                     + ($market['score'] * self::WEIGHT_MARKET);

        // 7. Financial calculations
        $sqft       = (int) $property->building_area_total;
        $year_built = (int) $property->year_built;
        $list_price = (float) $property->list_price;
        $raw_arv    = (float) $arv_data['estimated_arv'];

        $road_discount = self::ROAD_ARV_DISCOUNT[$road_analysis['road_type']] ?? 0;
        $arv = $road_discount > 0 ? round($raw_arv * (1 - $road_discount), 2) : $raw_arv;

        $actual_tax_rate = self::get_actual_tax_rate($listing_id, $list_price);

        $fin = self::calculate_financials(
            $arv, $list_price, $sqft, $year_built,
            $remarks, $city_metrics['avg_dom'] ?? 30,
            $actual_tax_rate
        );

        // 8. Thresholds and risk grade
        $thresholds = self::get_adaptive_thresholds(
            $arv_data['market_strength'] ?? 'balanced',
            $arv_data['avg_sale_to_list'] ?? 1.0,
            $arv_data['arv_confidence'] ?? 'medium'
        );

        $deal_risk_grade = self::calculate_deal_risk_grade(
            $arv_data['arv_confidence'] ?? 'none',
            $fin['breakeven_arv'],
            $arv,
            $arv_data['comps'],
            (int) ($property->days_on_market ?? 0),
            $arv_data['comp_count']
        );

        // 9. Build result and store (disqualified = 0 by default from build_result_data)
        $data = self::build_result_data(
            $property, $arv_data, $road_analysis, $fin,
            $total_score, $financial, $prop_score, $location, $market,
            $arv, $run_date
        );
        $data['applied_thresholds_json'] = json_encode($thresholds);
        $data['deal_risk_grade']         = $deal_risk_grade;
        if ($report_id) {
            $data['report_id'] = $report_id;
        }

        Flip_Database::upsert_result($data);

        return [
            'success'     => true,
            'listing_id'  => $listing_id,
            'total_score' => round($total_score, 2),
        ];
    }

    /**
     * Fetch listings matching analysis filters from target cities.
     */
    private static function fetch_properties(array $cities, int $limit, array $filters = []): array {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $where  = ["s.property_type = 'Residential'", "s.list_price > 0", "s.building_area_total > 0"];
        $params = [];
        $join   = '';

        // Property sub types
        $sub_types = !empty($filters['property_sub_types']) ? $filters['property_sub_types'] : ['Single Family Residence'];
        $ph = implode(',', array_fill(0, count($sub_types), '%s'));
        $where[] = "s.property_sub_type IN ({$ph})";
        $params  = array_merge($params, $sub_types);

        // Statuses
        $statuses = !empty($filters['statuses']) ? $filters['statuses'] : ['Active'];
        $ph = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "s.standard_status IN ({$ph})";
        $params  = array_merge($params, $statuses);

        // Cities
        $ph = implode(',', array_fill(0, count($cities), '%s'));
        $where[] = "s.city IN ({$ph})";
        $params  = array_merge($params, $cities);

        // Sewer (requires JOIN to bme_listing_details)
        if (!empty($filters['sewer_public_only'])) {
            $join = "INNER JOIN {$wpdb->prefix}bme_listing_details d ON s.listing_id = d.listing_id";
            $where[] = "d.sewer LIKE '%%Public Sewer%%'";
        }

        // Days on market range
        if (!empty($filters['min_dom'])) {
            $where[]  = "s.days_on_market >= %d";
            $params[] = (int) $filters['min_dom'];
        }
        if (!empty($filters['max_dom'])) {
            $where[]  = "s.days_on_market <= %d";
            $params[] = (int) $filters['max_dom'];
        }

        // List date range
        if (!empty($filters['list_date_from'])) {
            $where[]  = "s.listing_contract_date >= %s";
            $params[] = $filters['list_date_from'];
        }
        if (!empty($filters['list_date_to'])) {
            $where[]  = "s.listing_contract_date <= %s";
            $params[] = $filters['list_date_to'];
        }

        // Year built range
        if (!empty($filters['year_built_min'])) {
            $where[]  = "s.year_built >= %d";
            $params[] = (int) $filters['year_built_min'];
        }
        if (!empty($filters['year_built_max'])) {
            $where[]  = "s.year_built <= %d";
            $params[] = (int) $filters['year_built_max'];
        }

        // Price range
        if (!empty($filters['min_price'])) {
            $where[]  = "s.list_price >= %f";
            $params[] = (float) $filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[]  = "s.list_price <= %f";
            $params[] = (float) $filters['max_price'];
        }

        // Sqft range
        if (!empty($filters['min_sqft'])) {
            $where[]  = "s.building_area_total >= %d";
            $params[] = (int) $filters['min_sqft'];
        }
        if (!empty($filters['max_sqft'])) {
            $where[]  = "s.building_area_total <= %d";
            $params[] = (int) $filters['max_sqft'];
        }

        // Lot size
        if (!empty($filters['min_lot_acres'])) {
            $where[]  = "s.lot_size_acres >= %f";
            $params[] = (float) $filters['min_lot_acres'];
        }

        // Beds / baths
        if (!empty($filters['min_beds'])) {
            $where[]  = "s.bedrooms_total >= %d";
            $params[] = (int) $filters['min_beds'];
        }
        if (!empty($filters['min_baths'])) {
            $where[]  = "s.bathrooms_total >= %f";
            $params[] = (float) $filters['min_baths'];
        }

        // Garage
        if (!empty($filters['has_garage'])) {
            $where[] = "s.garage_spaces > 0";
        }

        $params[] = $limit;
        $where_sql = implode(' AND ', $where);

        // Query active listings
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$summary_table} s {$join}
             WHERE {$where_sql}
             ORDER BY s.list_price ASC
             LIMIT %d",
            $params
        ));

        // Also query archive table if Closed status is included
        if (in_array('Closed', $statuses, true)) {
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $archive_join  = str_replace('bme_listing_details', 'bme_listing_details_archive', $join);
            $archive_results = $wpdb->get_results($wpdb->prepare(
                "SELECT s.* FROM {$archive_table} s {$archive_join}
                 WHERE {$where_sql}
                 ORDER BY s.list_price ASC
                 LIMIT %d",
                $params
            ));
            if ($archive_results) {
                $results = array_merge($results, $archive_results);
                // Re-sort by list_price and apply limit
                usort($results, fn($a, $b) => (float) $a->list_price <=> (float) $b->list_price);
                $results = array_slice($results, 0, $limit);
            }
        }

        return $results;
    }

    /**
     * Fetch specific properties by listing_id (for monitor incremental runs).
     */
    private static function fetch_properties_by_ids(array $listing_ids): array {
        global $wpdb;
        if (empty($listing_ids)) {
            return [];
        }

        $table = $wpdb->prefix . 'bme_listing_summary';
        $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
        $ids = array_map('intval', $listing_ids);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id IN ({$placeholders})",
            $ids
        ));
    }

    /**
     * Fetch just listing_ids matching criteria (for monitor new-listing detection).
     */
    public static function fetch_matching_listing_ids(array $cities, array $filters = []): array {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $where  = ["s.property_type = 'Residential'", "s.list_price > 0", "s.building_area_total > 0"];
        $params = [];
        $join   = '';

        $sub_types = !empty($filters['property_sub_types']) ? $filters['property_sub_types'] : ['Single Family Residence'];
        $ph = implode(',', array_fill(0, count($sub_types), '%s'));
        $where[] = "s.property_sub_type IN ({$ph})";
        $params  = array_merge($params, $sub_types);

        $statuses = !empty($filters['statuses']) ? $filters['statuses'] : ['Active'];
        $ph = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "s.standard_status IN ({$ph})";
        $params  = array_merge($params, $statuses);

        $ph = implode(',', array_fill(0, count($cities), '%s'));
        $where[] = "s.city IN ({$ph})";
        $params  = array_merge($params, $cities);

        if (!empty($filters['sewer_public_only'])) {
            $join = "INNER JOIN {$wpdb->prefix}bme_listing_details d ON s.listing_id = d.listing_id";
            $where[] = "d.sewer LIKE '%%Public Sewer%%'";
        }
        if (!empty($filters['min_price'])) {
            $where[]  = "s.list_price >= %f";
            $params[] = (float) $filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[]  = "s.list_price <= %f";
            $params[] = (float) $filters['max_price'];
        }
        if (!empty($filters['min_sqft'])) {
            $where[]  = "s.building_area_total >= %d";
            $params[] = (int) $filters['min_sqft'];
        }
        if (!empty($filters['min_beds'])) {
            $where[]  = "s.bedrooms_total >= %d";
            $params[] = (int) $filters['min_beds'];
        }
        if (!empty($filters['min_baths'])) {
            $where[]  = "s.bathrooms_total >= %f";
            $params[] = (float) $filters['min_baths'];
        }
        if (!empty($filters['min_dom'])) {
            $where[]  = "s.days_on_market >= %d";
            $params[] = (int) $filters['min_dom'];
        }
        if (!empty($filters['max_dom'])) {
            $where[]  = "s.days_on_market <= %d";
            $params[] = (int) $filters['max_dom'];
        }
        if (!empty($filters['list_date_from'])) {
            $where[]  = "s.listing_contract_date >= %s";
            $params[] = $filters['list_date_from'];
        }
        if (!empty($filters['list_date_to'])) {
            $where[]  = "s.listing_contract_date <= %s";
            $params[] = $filters['list_date_to'];
        }
        if (!empty($filters['year_built_min'])) {
            $where[]  = "s.year_built >= %d";
            $params[] = (int) $filters['year_built_min'];
        }
        if (!empty($filters['year_built_max'])) {
            $where[]  = "s.year_built <= %d";
            $params[] = (int) $filters['year_built_max'];
        }
        if (!empty($filters['max_sqft'])) {
            $where[]  = "s.building_area_total <= %d";
            $params[] = (int) $filters['max_sqft'];
        }
        if (!empty($filters['min_lot_acres'])) {
            $where[]  = "s.lot_size_acres >= %f";
            $params[] = (float) $filters['min_lot_acres'];
        }
        if (!empty($filters['has_garage'])) {
            $where[] = "s.garage_spaces > 0";
        }

        $where_sql = implode(' AND ', $where);

        return $wpdb->get_col($wpdb->prepare(
            "SELECT s.listing_id FROM {$summary_table} s {$join} WHERE {$where_sql}",
            $params
        ));
    }

    /**
     * Check pre-financial auto-disqualifiers.
     *
     * @param object      $property           Row from bme_listing_summary.
     * @param array       $arv_data           ARV calculation result.
     * @param string|null $remarks            Public remarks (for distress keyword check).
     * @param string|null $property_condition  Property condition from bme_listing_details.
     * @return string|null Reason for disqualification, or null if passed.
     */
    private static function check_disqualifiers(
        object $property,
        array $arv_data,
        ?string $remarks = null,
        ?string $property_condition = null
    ): ?string {
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

        // New construction / near-new disqualifier
        $year_built = (int) $property->year_built;
        if ($year_built > 0) {
            $current_year = (int) wp_date('Y');
            $age = $current_year - $year_built;
            $has_distress = self::has_distress_signals($remarks);
            $condition_is_poor = self::condition_indicates_distress($property_condition);

            // Age ≤ 5: auto-DQ unless distress signals or poor condition
            if ($age <= 5 && !$has_distress && !$condition_is_poor) {
                return "Recent construction ({$year_built}) - minimal renovation potential";
            }

            // Property condition "New Construction" or "Excellent" on < 15 year old property
            if (self::condition_indicates_pristine($property_condition, $age) && !$has_distress) {
                return "Property condition '{$property_condition}' on {$year_built} build - no renovation potential";
            }
        }

        $market_str = $arv_data['market_strength'] ?? 'balanced';
        $max_ratio = self::MARKET_MAX_PRICE_ARV_RATIO[$market_str] ?? 0.85;
        if ($arv > 0 && $list_price > $arv * $max_ratio) {
            $ratio = round($list_price / $arv, 2);
            $pct = round($max_ratio * 100);
            return "List price too close to ARV (ratio: {$ratio}, max: {$pct}% [{$market_str}])";
        }

        $base_rehab_ppsf  = self::get_rehab_per_sqft($year_built);
        $age_mult         = self::get_age_condition_multiplier($year_built);
        $remarks_mult     = self::get_remarks_rehab_multiplier($remarks);
        $estimated_rehab  = $sqft * max(2.0, $base_rehab_ppsf * $age_mult * $remarks_mult);
        if ($arv > 0 && $estimated_rehab > $arv * 0.35) {
            return 'Rehab estimate exceeds 35% of ARV';
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
     * Post-calculation disqualifier using financed numbers and adaptive thresholds.
     *
     * @return string|null Reason for disqualification, or null if passed.
     */
    private static function check_post_calc_disqualifiers(float $profit, float $roi, array $thresholds, string $arv_confidence = 'medium'): ?string {
        // Confidence safety margin — require more profit/ROI when ARV is uncertain
        $confidence_factor = match ($arv_confidence) {
            'high'   => 1.0,
            'medium' => 1.0,
            'low'    => 1.25,  // 25% stricter
            default  => 1.5,   // 50% stricter for 'none'
        };

        $min_profit = $thresholds['min_profit'] * $confidence_factor;
        $min_roi    = $thresholds['min_roi'] * $confidence_factor;
        $market     = $thresholds['market_strength'];
        $suffix     = ($market !== 'balanced')
            ? ' [' . $market . ' market]'
            : '';
        if ($confidence_factor > 1.0) {
            $suffix .= ' [low ARV confidence]';
        }

        if ($profit < $min_profit) {
            return 'Estimated profit ($' . number_format($profit) . ') below minimum ($'
                . number_format($min_profit) . ')' . $suffix;
        }

        if ($roi < $min_roi) {
            return 'Estimated ROI (' . round($roi, 1) . '%) below minimum ('
                . round($min_roi, 1) . '%)' . $suffix;
        }

        return null;
    }

    /**
     * Store a disqualified property result (pre-calculation DQ).
     */
    private static function store_disqualified(object $property, array $arv_data, string $reason, string $run_date, ?int $report_id = null): void {
        $thresholds = self::get_adaptive_thresholds(
            $arv_data['market_strength'] ?? 'balanced',
            $arv_data['avg_sale_to_list'] ?? 1.0,
            $arv_data['arv_confidence'] ?? 'medium'
        );

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
            'rehab_multiplier'         => 1.0,
            'age_condition_multiplier' => 1.0,
            'days_on_market'           => (int) ($property->days_on_market ?? 0),
            'list_price'               => (float) $property->list_price,
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
            'near_viable'         => 0,
            'applied_thresholds_json' => json_encode($thresholds),
            'annualized_roi'      => 0,
            'breakeven_arv'       => 0,
            'deal_risk_grade'     => null,
            'lead_paint_flag'     => ((int) $property->year_built > 0 && (int) $property->year_built < self::LEAD_PAINT_YEAR) ? 1 : 0,
            'transfer_tax_buy'    => 0,
            'transfer_tax_sell'   => 0,
        ];

        if ($report_id) {
            $data['report_id'] = $report_id;
        }

        Flip_Database::upsert_result($data);
    }

    /**
     * Get rehab cost per sqft based on property age.
     *
     * Uses smooth continuous formula instead of step function to avoid
     * discontinuities at boundaries (e.g., age 10=$15 → age 11=$30).
     * Formula: clamp(10 + age × 0.7, 5, 65)
     *
     * v0.11.0: Floor lowered from $12 to $5 to allow realistic near-zero
     * rehab when combined with age_condition_multiplier for new properties.
     */
    public static function get_rehab_per_sqft(int $year_built): float {
        if ($year_built <= 0) return 45.0;
        $age = (int) wp_date('Y') - $year_built;

        return max(5.0, min(65.0, 10.0 + $age * 0.7));
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
     * Get scaled contingency rate based on rehab scope.
     *
     * Industry standard: 8% cosmetic → 20% major/gut renovations.
     */
    private static function get_contingency_rate(float $effective_per_sqft): float {
        return match (true) {
            $effective_per_sqft <= 20 => 0.08,  // Cosmetic
            $effective_per_sqft <= 35 => 0.12,  // Moderate
            $effective_per_sqft <= 50 => 0.15,  // Significant
            default                   => 0.20,  // Major/Gut
        };
    }

    /**
     * Estimate hold period based on rehab scope and local market velocity.
     */
    private static function estimate_hold_months(float $rehab_per_sqft, float $area_avg_dom = 30): int {
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

    /**
     * Calculate deal risk grade (A-F) combining multiple risk factors.
     *
     * @param string $arv_confidence  ARV confidence level (high, medium, low, none).
     * @param float  $breakeven_arv   Breakeven ARV from financials.
     * @param float  $estimated_arv   Estimated ARV.
     * @param array  $comps           Array of comp objects from ARV calculation.
     * @param int    $dom             Property days on market.
     * @param int    $comp_count      Number of comps used.
     * @return string Grade letter: A, B, C, D, or F.
     */
    public static function calculate_deal_risk_grade(
        string $arv_confidence,
        float $breakeven_arv,
        float $estimated_arv,
        array $comps,
        int $dom,
        int $comp_count
    ): string {
        // Factor 1: ARV confidence (35%)
        $confidence_score = match ($arv_confidence) {
            'high'   => 100,
            'medium' => 65,
            'low'    => 30,
            default  => 10,
        };

        // Factor 2: Margin cushion (25%) — how far ARV is above breakeven
        $margin_score = 0;
        if ($estimated_arv > 0 && $breakeven_arv > 0) {
            $breakeven_margin = (($estimated_arv - $breakeven_arv) / $estimated_arv) * 100;
            $margin_score = min(100, max(0, $breakeven_margin * 5));
        }

        // Factor 3: Comp consistency (20%) — inverse of price variance (CV)
        $consistency_score = 50; // default when insufficient data
        if (count($comps) >= 3) {
            $prices = array_map(function ($c) {
                return (float) ($c->adjusted_ppsf ?? $c->ppsf ?? 0);
            }, $comps);
            $prices = array_filter($prices, fn($p) => $p > 0);

            if (count($prices) >= 3) {
                $mean = array_sum($prices) / count($prices);
                if ($mean > 0) {
                    $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $prices)) / count($prices);
                    $cv = sqrt($variance) / $mean;
                    // CV of 0 = perfect consistency (100), CV of 0.30+ = very inconsistent (0)
                    $consistency_score = min(100, max(0, (1 - $cv / 0.30) * 100));
                }
            }
        }

        // Factor 4: Market velocity (10%) — DOM indicates demand
        $velocity_score = match (true) {
            $dom <= 15 => 100,
            $dom <= 30 => 80,
            $dom <= 60 => 50,
            $dom <= 90 => 30,
            default    => 10,
        };

        // Factor 5: Comp count (10%) — more data = more confidence
        $count_score = match (true) {
            $comp_count >= 8 => 100,
            $comp_count >= 5 => 80,
            $comp_count >= 3 => 50,
            default          => 20,
        };

        // Weighted composite
        $composite = ($confidence_score * 0.35)
                   + ($margin_score * 0.25)
                   + ($consistency_score * 0.20)
                   + ($velocity_score * 0.10)
                   + ($count_score * 0.10);

        return match (true) {
            $composite >= 80 => 'A',
            $composite >= 65 => 'B',
            $composite >= 50 => 'C',
            $composite >= 35 => 'D',
            default          => 'F',
        };
    }

    /**
     * Look up actual property tax rate from MLS financial data.
     *
     * Returns the rate as a decimal (e.g. 0.0142 for 1.42%), or null if
     * no tax data is available (falls back to MA average in calculate_financials).
     */
    private static function get_actual_tax_rate(int $listing_id, float $list_price): ?float {
        if ($list_price <= 0) {
            return null;
        }

        global $wpdb;
        $financial_table = $wpdb->prefix . 'bme_listing_financial';

        $tax_annual = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_annual_amount FROM {$financial_table} WHERE listing_id = %d LIMIT 1",
            $listing_id
        ));

        if ($tax_annual && (float) $tax_annual > 0) {
            $rate = (float) $tax_annual / $list_price;
            // Sanity check: MA rates range from ~0.5% to ~3%
            if ($rate >= 0.003 && $rate <= 0.04) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * Look up property condition from MLS listing details.
     *
     * @param int $listing_id MLS listing ID.
     * @return string|null Condition value (e.g. "Excellent", "New Construction", "Poor") or null.
     */
    private static function get_property_condition(int $listing_id): ?string {
        global $wpdb;
        $details_table = $wpdb->prefix . 'bme_listing_details';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT property_condition FROM {$details_table} WHERE listing_id = %d LIMIT 1",
            $listing_id
        ));
    }

    /**
     * Check if listing remarks contain distress signals that indicate
     * a property may need renovation despite being recently built.
     */
    private static function has_distress_signals(?string $remarks): bool {
        if (empty($remarks)) return false;
        $lower = strtolower($remarks);

        // Strong signals — unambiguous distress, never used in marketing
        $strong_keywords = [
            'foreclosure', 'bank owned', 'reo', 'short sale',
            'fire damage', 'water damage', 'condemned', 'court ordered',
            'estate sale', 'probate', 'uninhabitable',
        ];
        foreach ($strong_keywords as $keyword) {
            if (str_contains($lower, $keyword)) return true;
        }

        // Weak signals — commonly used in marketing copy ("settle for a fixer upper",
        // "no need for a handyman"). Require they NOT appear in a negation context.
        // Uses word-boundary matching and expanded negation detection.
        $weak_keywords = [
            'fixer', 'handyman', 'needs work', 'as-is', 'as is',
            'tear down', 'teardown',
        ];
        $negation_phrases = [
            'no need', 'don\'t need', 'doesn\'t need', 'not a fixer', 'no fixer',
            'settle for', 'instead of', 'rather than', 'unlike',
            'forget the', 'won\'t need', 'without the',
            'why settle', 'say goodbye', 'no more',
            'never have to', 'don\'t have to', 'you won\'t',
        ];
        foreach ($weak_keywords as $keyword) {
            // Word-boundary aware search: find all occurrences
            $offset = 0;
            $found_unegated = false;
            while (($pos = strpos($lower, $keyword, $offset)) !== false) {
                // Basic word boundary: char before/after shouldn't be a letter
                if ($pos > 0 && ctype_alpha($lower[$pos - 1])) {
                    $offset = $pos + 1;
                    continue;
                }
                $end = $pos + strlen($keyword);
                if ($end < strlen($lower) && ctype_alpha($lower[$end])) {
                    $offset = $pos + 1;
                    continue;
                }

                // Check negation context: 120 chars before, 60 chars after
                $context_start = max(0, $pos - 120);
                $context_end = min(strlen($lower), $end + 60);
                $context = substr($lower, $context_start, $context_end - $context_start);

                $negated = false;
                foreach ($negation_phrases as $neg) {
                    if (str_contains($context, $neg)) {
                        $negated = true;
                        break;
                    }
                }
                if (!$negated) {
                    $found_unegated = true;
                    break;
                }
                $offset = $pos + 1;
            }
            if ($found_unegated) return true;
        }
        return false;
    }

    /**
     * Check if property condition field indicates distress/renovation need.
     */
    private static function condition_indicates_distress(?string $condition): bool {
        if (empty($condition)) return false;
        $lower = strtolower(trim($condition));
        return str_contains($lower, 'poor')
            || str_contains($lower, 'needs')
            || str_contains($lower, 'fixer');
    }

    /**
     * Check if property condition field indicates pristine/new state
     * on a relatively new property (< 15 years old).
     */
    private static function condition_indicates_pristine(?string $condition, int $age): bool {
        if (empty($condition) || $age > 15) return false;
        $lower = strtolower(trim($condition));
        return in_array($lower, ['new construction', 'excellent', 'new/never occupied'], true);
    }

    /**
     * Get age-based condition multiplier for rehab estimates.
     *
     * Near-new properties without distress signals need minimal rehab.
     * Applied BEFORE the remarks multiplier in calculate_financials().
     */
    public static function get_age_condition_multiplier(int $year_built): float {
        if ($year_built <= 0) return 1.0;
        $age = (int) wp_date('Y') - $year_built;

        return match (true) {
            $age <= 5  => 0.10,  // Brand new — basically nothing to do
            $age <= 10 => 0.30,
            $age <= 15 => 0.50,
            $age <= 20 => 0.75,
            default    => 1.0,   // Full rehab formula applies
        };
    }
}
