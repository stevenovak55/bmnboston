<?php
/**
 * Rental Hold & BRRRR Analysis Calculator.
 *
 * Provides multi-exit strategy analysis for flip candidates:
 * - Rental Hold: cash flow, NOI, cap rate, DSCR, multi-year projections
 * - BRRRR: Buy-Rehab-Rent-Refinance-Repeat with refi modeling
 * - Strategy Recommendation: scores and compares all three strategies
 *
 * v0.16.0: Initial implementation
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Rental_Calculator {

    /**
     * City-level rental rate lookup ($/sqft/month).
     * Based on Greater Boston market data (2025-2026).
     */
    const RENTAL_RATES_PER_SQFT = [
        'Reading'        => 2.00,
        'Melrose'        => 2.10,
        'Stoneham'       => 1.85,
        'Burlington'     => 1.90,
        'Andover'        => 2.20,
        'North Andover'  => 2.00,
        'Wakefield'      => 1.95,
        'Woburn'         => 1.85,
        'Winchester'     => 2.30,
        'Medford'        => 2.15,
        'Malden'         => 2.05,
        'Saugus'         => 1.80,
        'Lynnfield'      => 2.10,
        'Wilmington'     => 1.85,
        'Tewksbury'      => 1.75,
        'Billerica'      => 1.80,
        'Chelmsford'     => 1.85,
        'Lexington'      => 2.40,
        'Arlington'      => 2.25,
        'Somerville'     => 2.50,
        'Cambridge'      => 2.80,
        'Boston'         => 2.70,
    ];

    const DEFAULT_RENTAL_RATE = 1.80;    // Fallback $/sqft/month
    const VALUE_RENT_RATIO    = 0.007;   // 0.7% rule for value-based fallback
    const DEPRECIATION_YEARS  = 27.5;    // IRS residential depreciation schedule
    const LAND_VALUE_PCT      = 0.20;    // Assume 20% of value is land (not depreciable)

    // ---------------------------------------------------------------
    // Rental Income Estimation
    // ---------------------------------------------------------------

    /**
     * Estimate monthly rental income using three-tier approach.
     *
     * @param array      $property_data  Property snapshot
     * @param float|null $user_override  User-specified monthly rent
     * @return array {amount, annual, source, confidence}
     */
    public static function estimate_monthly_rent(array $property_data, ?float $user_override = null): array {
        // Tier 0: MLS gross income (most reliable for income properties)
        $mls_gross = $property_data['gross_income'] ?? null;
        if ($mls_gross !== null && $mls_gross > 0) {
            $monthly = round($mls_gross / 12, 2);
            return [
                'amount'     => $monthly,
                'annual'     => round($mls_gross, 2),
                'source'     => 'mls_gross_income',
                'confidence' => 'high',
            ];
        }

        // Tier 1: User override
        if ($user_override !== null && $user_override > 0) {
            return [
                'amount'     => round($user_override, 2),
                'annual'     => round($user_override * 12, 2),
                'source'     => 'user_override',
                'confidence' => 'high',
            ];
        }

        $sqft = (int) ($property_data['building_area_total'] ?? 0);
        $city = $property_data['city'] ?? '';
        $value = (float) ($property_data['list_price'] ?? 0);

        // Tier 2: City-level rate lookup
        $defaults = Flip_Database::get_rental_defaults();
        $city_overrides = $defaults['rental_rate_overrides'] ?? [];

        // Check user-configured city rate first, then hardcoded rates
        $rate = $city_overrides[$city] ?? self::RENTAL_RATES_PER_SQFT[$city] ?? null;

        if ($rate !== null && $sqft > 0) {
            $monthly = round($sqft * $rate, 2);
            return [
                'amount'     => $monthly,
                'annual'     => round($monthly * 12, 2),
                'source'     => 'city_rate',
                'confidence' => isset($city_overrides[$city]) ? 'high' : 'medium',
                'rate_used'  => $rate,
            ];
        }

        // Tier 2b: Default rate with sqft
        if ($sqft > 0) {
            $monthly = round($sqft * self::DEFAULT_RENTAL_RATE, 2);
            return [
                'amount'     => $monthly,
                'annual'     => round($monthly * 12, 2),
                'source'     => 'default_rate',
                'confidence' => 'medium',
                'rate_used'  => self::DEFAULT_RENTAL_RATE,
            ];
        }

        // Tier 3: Value-based fallback (0.7% rule)
        if ($value > 0) {
            $monthly = round($value * self::VALUE_RENT_RATIO, 2);
            return [
                'amount'     => $monthly,
                'annual'     => round($monthly * 12, 2),
                'source'     => 'value_formula',
                'confidence' => 'low',
            ];
        }

        return [
            'amount'     => 0,
            'annual'     => 0,
            'source'     => 'none',
            'confidence' => 'none',
        ];
    }

    // ---------------------------------------------------------------
    // Rental Hold Analysis
    // ---------------------------------------------------------------

    /**
     * Calculate full rental hold analysis.
     *
     * @param array $flip_fin       From Flip_Analyzer::calculate_financials()
     * @param array $property_data  Property snapshot
     * @param array $overrides      User overrides for rates/assumptions
     * @return array Rental analysis results
     */
    public static function calculate_rental(array $flip_fin, array $property_data, array $overrides = []): array {
        $defaults = Flip_Database::get_rental_defaults();
        $params   = array_merge($defaults, $overrides);

        // Property value = ARV after rehab (rental is post-renovation)
        // ARV comes from property_data (set by attach_rental_analysis), NOT flip_fin
        $arv        = (float) ($property_data['estimated_arv'] ?? $property_data['list_price'] ?? 0);
        $list_price = (float) ($property_data['list_price'] ?? 0);
        $sqft       = (int) ($property_data['building_area_total'] ?? 0);
        $year_built = (int) ($property_data['year_built'] ?? 1980);
        $units      = (int) ($property_data['number_of_units_total'] ?? 1);
        $is_multifamily = self::is_multifamily($property_data['property_sub_type'] ?? '');

        // Apply multifamily-specific defaults if not already overridden
        if ($is_multifamily) {
            $params = self::apply_multifamily_defaults($params);
        }

        // Total acquisition cost (purchase + rehab + closing)
        // Note: flip_fin['rehab_cost'] already includes contingency
        $total_rehab      = (float) ($flip_fin['rehab_cost'] ?? 0);
        $purchase_closing  = (float) ($flip_fin['purchase_closing'] ?? $list_price * 0.015);
        $total_investment  = $list_price + $total_rehab + $purchase_closing;

        // Estimate rent
        $rent_override = $overrides['monthly_rent'] ?? null;
        $rent_data     = self::estimate_monthly_rent($property_data, $rent_override);
        $monthly_rent  = $rent_data['amount'];
        $annual_gross  = $monthly_rent * 12;

        // Operating expenses
        $actual_tax_rate = (float) ($property_data['actual_tax_rate'] ?? $flip_fin['actual_tax_rate'] ?? 0.013);
        $expenses = self::calculate_operating_expenses($arv, $monthly_rent, $actual_tax_rate, $params);

        // Core metrics
        $noi = $annual_gross - $expenses['total_annual'];
        $monthly_noi = $noi / 12;

        // Vacancy-adjusted gross income
        $vacancy_rate = (float) $params['vacancy_rate'];
        $effective_gross = $annual_gross * (1 - $vacancy_rate);

        // Cap Rate = NOI / Property Value (use ARV since this is post-rehab)
        $cap_rate = $arv > 0 ? round(($noi / $arv) * 100, 2) : 0;

        // Cash-on-Cash (no leverage in rental hold scenario)
        $annual_cash_flow = $noi;  // No debt service in cash scenario
        $cash_on_cash = $total_investment > 0
            ? round(($annual_cash_flow / $total_investment) * 100, 2) : 0;

        // GRM = Price / Annual Gross Rent
        $grm = $annual_gross > 0 ? round($total_investment / $annual_gross, 1) : 0;

        // Tax benefits (depreciation)
        $tax = self::calculate_tax_benefits($arv, self::LAND_VALUE_PCT, (float) $params['marginal_tax_rate']);

        // Multi-year projections
        $projections = self::project_returns(
            $arv, $noi, $total_investment,
            (float) $params['appreciation_rate'],
            (float) $params['rent_growth_rate'],
            0,  // No debt service for cash rental hold
            [1, 5, 10, 20]
        );

        // Monthly P&L
        $monthly_expenses_total = $expenses['total_annual'] / 12;

        return [
            'monthly_rent'        => $monthly_rent,
            'rent_source'         => $rent_data['source'],
            'rent_confidence'     => $rent_data['confidence'],
            'annual_gross_income' => round($annual_gross, 2),
            'effective_gross'     => round($effective_gross, 2),
            'vacancy_rate'        => $vacancy_rate,
            'expenses'            => $expenses,
            'noi'                 => round($noi, 2),
            'monthly_noi'         => round($monthly_noi, 2),
            'cap_rate'            => $cap_rate,
            'cash_on_cash'        => $cash_on_cash,
            'monthly_cash_flow'   => round($monthly_noi, 2),
            'annual_cash_flow'    => round($annual_cash_flow, 2),
            'grm'                 => $grm,
            'dscr'                => null,  // No debt in cash scenario
            'total_investment'    => round($total_investment, 2),
            'property_value'      => round($arv, 2),
            'tax_benefits'        => $tax,
            'projections'         => $projections,
            'pnl_monthly'         => [
                'income'   => round($monthly_rent, 2),
                'expenses' => round($monthly_expenses_total, 2),
                'net'      => round($monthly_rent - $monthly_expenses_total, 2),
            ],
            'params_used'         => [
                'vacancy_rate'        => $vacancy_rate,
                'management_fee_rate' => (float) $params['management_fee_rate'],
                'maintenance_rate'    => (float) $params['maintenance_rate'],
                'capex_reserve_rate'  => (float) $params['capex_reserve_rate'],
                'insurance_rate'      => (float) $params['insurance_rate'],
                'appreciation_rate'   => (float) $params['appreciation_rate'],
                'rent_growth_rate'    => (float) $params['rent_growth_rate'],
                'is_multifamily'      => $is_multifamily,
            ],
            'per_unit'            => $units > 1 ? [
                'units'             => $units,
                'price_per_unit'    => round($list_price / $units, 0),
                'arv_per_unit'      => $arv > 0 ? round($arv / $units, 0) : 0,
                'rent_per_unit'     => round($monthly_rent / $units, 2),
                'sqft_per_unit'     => $sqft > 0 ? round($sqft / $units, 0) : 0,
                'noi_per_unit'      => round($noi / $units, 2),
                'expense_per_unit'  => round($expenses['total_annual'] / $units, 2),
            ] : null,
        ];
    }

    /**
     * Calculate annual operating expenses breakdown.
     */
    public static function calculate_operating_expenses(
        float $property_value,
        float $monthly_rent,
        float $actual_tax_rate,
        array $params = []
    ): array {
        $annual_rent = $monthly_rent * 12;

        $property_tax  = round($property_value * $actual_tax_rate, 2);
        $insurance     = round($property_value * (float) ($params['insurance_rate'] ?? 0.006), 2);
        $management    = round($annual_rent * (float) ($params['management_fee_rate'] ?? 0.08), 2);
        $maintenance   = round($property_value * (float) ($params['maintenance_rate'] ?? 0.01), 2);
        $vacancy       = round($annual_rent * (float) ($params['vacancy_rate'] ?? 0.05), 2);
        $capex_reserve = round($annual_rent * (float) ($params['capex_reserve_rate'] ?? 0.05), 2);

        $total = $property_tax + $insurance + $management + $maintenance + $vacancy + $capex_reserve;

        return [
            'property_tax'  => $property_tax,
            'insurance'     => $insurance,
            'management'    => $management,
            'maintenance'   => $maintenance,
            'vacancy'       => $vacancy,
            'capex_reserve' => $capex_reserve,
            'total_annual'  => round($total, 2),
            'total_monthly' => round($total / 12, 2),
        ];
    }

    /**
     * Calculate tax benefits from depreciation.
     */
    public static function calculate_tax_benefits(
        float $property_value,
        float $land_value_pct = 0.20,
        float $marginal_tax_rate = 0.32
    ): array {
        $depreciable_basis = $property_value * (1 - $land_value_pct);
        $annual_depreciation = $depreciable_basis / self::DEPRECIATION_YEARS;
        $annual_tax_savings = $annual_depreciation * $marginal_tax_rate;

        return [
            'depreciable_basis'    => round($depreciable_basis, 2),
            'annual_depreciation'  => round($annual_depreciation, 2),
            'annual_tax_savings'   => round($annual_tax_savings, 2),
            'monthly_tax_savings'  => round($annual_tax_savings / 12, 2),
            'marginal_tax_rate'    => $marginal_tax_rate,
        ];
    }

    /**
     * Generate multi-year return projections.
     */
    public static function project_returns(
        float $property_value,
        float $annual_noi,
        float $total_investment,
        float $appreciation_rate,
        float $rent_growth_rate,
        float $annual_debt_service = 0,
        array $years = [1, 5, 10, 20]
    ): array {
        $projections = [];

        foreach ($years as $y) {
            // Property appreciation (compound)
            $future_value = $property_value * pow(1 + $appreciation_rate, $y);
            $equity_gain  = $future_value - $property_value;

            // Cumulative cash flow with rent growth
            $cumulative_cf = 0;
            $current_noi = $annual_noi;
            for ($i = 1; $i <= $y; $i++) {
                $cumulative_cf += $current_noi - $annual_debt_service;
                $current_noi *= (1 + $rent_growth_rate);
            }

            $total_return = $equity_gain + $cumulative_cf;
            // Guard against NaN: pow() of negative base with fractional exponent = NaN
            $growth_factor = $total_investment > 0 ? 1 + ($total_return / $total_investment) : 0;
            $annualized   = ($growth_factor > 0 && $y > 0)
                ? round((pow($growth_factor, 1 / $y) - 1) * 100, 1)
                : ($total_investment > 0 && $y > 0 ? round(($total_return / $total_investment / $y) * 100, 1) : 0);

            $projections[$y] = [
                'year'            => $y,
                'property_value'  => round($future_value, 0),
                'equity_gain'     => round($equity_gain, 0),
                'cumulative_cf'   => round($cumulative_cf, 0),
                'total_return'    => round($total_return, 0),
                'total_return_pct' => $total_investment > 0
                    ? round(($total_return / $total_investment) * 100, 1) : 0,
                'annualized_return' => $annualized,
            ];
        }

        return $projections;
    }

    // ---------------------------------------------------------------
    // BRRRR Analysis
    // ---------------------------------------------------------------

    /**
     * Calculate BRRRR (Buy, Rehab, Rent, Refinance, Repeat) analysis.
     *
     * @param array $flip_fin       From Flip_Analyzer::calculate_financials()
     * @param array $rental         From self::calculate_rental()
     * @param array $property_data  Property snapshot
     * @param array $overrides      User overrides for refi terms
     * @return array BRRRR analysis results
     */
    public static function calculate_brrrr(
        array $flip_fin,
        array $rental,
        array $property_data,
        array $overrides = []
    ): array {
        $defaults = Flip_Database::get_rental_defaults();
        $params   = array_merge($defaults, $overrides);
        $is_multifamily = self::is_multifamily($property_data['property_sub_type'] ?? '');

        // Apply multifamily-specific refi terms
        if ($is_multifamily) {
            $params = self::apply_multifamily_defaults($params);
        }

        $list_price  = (float) ($property_data['list_price'] ?? 0);
        // ARV comes from property_data (set by attach_rental_analysis), NOT flip_fin
        $arv         = (float) ($property_data['estimated_arv'] ?? 0);

        // Total cash invested before refinance
        // Note: flip_fin['rehab_cost'] already includes contingency
        $total_rehab      = (float) ($flip_fin['rehab_cost'] ?? 0);
        $purchase_closing  = (float) ($flip_fin['purchase_closing'] ?? $list_price * 0.015);
        $total_cash_in    = $list_price + $total_rehab + $purchase_closing;

        // Refinance modeling
        $refi = self::model_refinance(
            $arv,
            $total_cash_in,
            (float) $params['brrrr_refi_ltv'],
            (float) $params['brrrr_refi_rate'],
            (int) $params['brrrr_refi_term']
        );

        // Post-refi rental cash flow
        $monthly_rent    = (float) ($rental['monthly_rent'] ?? 0);
        $monthly_expenses = (float) ($rental['expenses']['total_monthly'] ?? 0);
        $monthly_debt    = $refi['monthly_payment'];
        $post_refi_cf    = $monthly_rent - $monthly_expenses - $monthly_debt;
        $annual_post_refi_cf = $post_refi_cf * 12;

        // DSCR = NOI / Annual Debt Service
        $noi = (float) ($rental['noi'] ?? 0);
        $annual_debt_service = $monthly_debt * 12;
        $dscr = $annual_debt_service > 0 ? round($noi / $annual_debt_service, 2) : null;

        // Post-refi cash-on-cash (based on cash left in deal)
        $cash_left = $refi['cash_left_in_deal'];
        $infinite_return = $cash_left <= 0;
        $post_refi_coc = (!$infinite_return && $cash_left > 0)
            ? round(($annual_post_refi_cf / $cash_left) * 100, 2)
            : null;

        // Multi-year projections WITH debt service
        $projections = self::project_returns(
            $arv, $noi,
            max(1, $cash_left),  // Avoid div-by-zero for infinite return
            (float) $params['appreciation_rate'],
            (float) $params['rent_growth_rate'],
            $annual_debt_service,
            [1, 5, 10, 20]
        );

        return [
            'total_cash_in'             => round($total_cash_in, 2),
            'purchase_price'            => round($list_price, 2),
            'rehab_cost'                => round($total_rehab, 2),
            'purchase_closing'          => round($purchase_closing, 2),
            'arv'                       => round($arv, 2),
            'refi_ltv'                  => $refi['refi_ltv'],
            'refi_loan'                 => $refi['refi_loan'],
            'refi_rate'                 => $refi['refi_rate'],
            'refi_term'                 => $refi['refi_term'],
            'monthly_payment'           => $refi['monthly_payment'],
            'cash_out'                  => $refi['cash_out'],
            'cash_left_in_deal'         => $refi['cash_left_in_deal'],
            'equity_captured'           => $refi['equity_captured'],
            'infinite_return'           => $infinite_return,
            'post_refi_monthly_cf'      => round($post_refi_cf, 2),
            'post_refi_annual_cf'       => round($annual_post_refi_cf, 2),
            'post_refi_cash_on_cash'    => $post_refi_coc,
            'dscr'                      => $dscr,
            'monthly_breakdown'         => [
                'rent'     => round($monthly_rent, 2),
                'expenses' => round($monthly_expenses, 2),
                'mortgage' => round($monthly_debt, 2),
                'net'      => round($post_refi_cf, 2),
            ],
            'projections'               => $projections,
        ];
    }

    /**
     * Model the refinance step of BRRRR.
     */
    public static function model_refinance(
        float $arv,
        float $total_cash_invested,
        float $refi_ltv = 0.75,
        float $refi_rate = 0.072,
        int $refi_term_years = 30
    ): array {
        $refi_loan = round($arv * $refi_ltv, 2);
        $monthly_payment = self::monthly_mortgage_payment($refi_loan, $refi_rate, $refi_term_years);

        // Cash out = refi loan proceeds (minus closing costs ~2%)
        $refi_closing = round($refi_loan * 0.02, 2);
        $cash_out = round($refi_loan - $refi_closing, 2);

        // Cash left in deal (negative means you pulled out MORE than you put in)
        $cash_left = round($total_cash_invested - $cash_out, 2);

        // Equity captured = ARV - refi loan
        $equity_captured = round($arv - $refi_loan, 2);

        return [
            'refi_loan'          => $refi_loan,
            'refi_ltv'           => $refi_ltv,
            'refi_rate'          => $refi_rate,
            'refi_term'          => $refi_term_years,
            'refi_closing'       => $refi_closing,
            'monthly_payment'    => $monthly_payment,
            'cash_out'           => $cash_out,
            'cash_left_in_deal'  => $cash_left,
            'equity_captured'    => $equity_captured,
        ];
    }

    /**
     * Calculate standard monthly mortgage payment (P&I) using amortization formula.
     */
    public static function monthly_mortgage_payment(
        float $loan_amount,
        float $annual_rate,
        int $term_years
    ): float {
        if ($loan_amount <= 0 || $annual_rate <= 0 || $term_years <= 0) {
            return 0;
        }

        $monthly_rate = $annual_rate / 12;
        $num_payments = $term_years * 12;

        // M = P * [r(1+r)^n] / [(1+r)^n - 1]
        $factor = pow(1 + $monthly_rate, $num_payments);
        $payment = $loan_amount * ($monthly_rate * $factor) / ($factor - 1);

        return round($payment, 2);
    }

    // ---------------------------------------------------------------
    // Strategy Recommendation
    // ---------------------------------------------------------------

    /**
     * Compare all three strategies and recommend the best one.
     *
     * Scoring formula: 70% financial metrics + 20% property/location quality + 10% photo condition.
     * Quality and photo components use strategy-specific weights.
     *
     * @param array      $flip_fin       From Flip_Analyzer::calculate_financials()
     * @param array      $rental         From self::calculate_rental()
     * @param array      $brrrr          From self::calculate_brrrr()
     * @param array      $quality_scores Quality scorer results {financial, property, location, market} (0-100 each)
     * @param array|null $photo_data     Photo analysis data from Claude Vision
     * @return array {recommended, scores, viable, reasoning}
     */
    public static function recommend_strategy(
        array $flip_fin,
        array $rental,
        array $brrrr,
        array $quality_scores = [],
        ?array $photo_data = null
    ): array {
        $has_quality = !empty($quality_scores);
        $has_photo   = !empty($photo_data);

        // --- Flip Financial Component (0-100) ---
        $flip_fin_raw = 0;
        $flip_profit = (float) ($flip_fin['estimated_profit'] ?? 0);
        $flip_risk   = $flip_fin['deal_risk_grade'] ?? 'C';
        $annualized  = (float) ($flip_fin['annualized_roi'] ?? 0);

        $flip_fin_raw += min(40, max(0, $annualized * 0.4));
        $flip_fin_raw += min(30, max(0, $flip_profit / 2000));
        $risk_map = ['A' => 30, 'B' => 24, 'C' => 18, 'D' => 12, 'F' => 6];
        $flip_fin_raw += $risk_map[$flip_risk] ?? 15;
        if ($flip_profit <= 0) {
            $flip_fin_raw = max(0, $flip_fin_raw - 40);
        }
        $flip_fin_raw = min(100, max(0, $flip_fin_raw));

        // Flip quality: property weighted highest (renovation potential matters most)
        $flip_quality = $has_quality
            ? ($quality_scores['financial'] ?? 50) * 0.30
              + ($quality_scores['property'] ?? 50) * 0.35
              + ($quality_scores['location'] ?? 50) * 0.25
              + ($quality_scores['market'] ?? 50) * 0.10
            : 50;

        // Flip photo: use existing flip_photo_score
        $flip_photo = $has_photo
            ? min(100, max(0, (float) ($photo_data['flip_photo_score'] ?? 50)))
            : 50;

        // --- Rental Financial Component (0-100) ---
        $rental_fin_raw = 0;
        $cap_rate    = (float) ($rental['cap_rate'] ?? 0);
        $rental_coc  = (float) ($rental['cash_on_cash'] ?? 0);
        $monthly_cf  = (float) ($rental['monthly_cash_flow'] ?? 0);
        $yr5_return  = (float) ($rental['projections'][5]['total_return_pct'] ?? 0);

        $rental_fin_raw += min(25, max(0, $cap_rate * 4));
        $rental_fin_raw += min(25, max(0, $rental_coc * 3));
        $rental_fin_raw += min(25, max(0, $monthly_cf / 20));
        $rental_fin_raw += min(25, max(0, $yr5_return * 0.25));
        if ($monthly_cf <= 0) {
            $rental_fin_raw = max(0, $rental_fin_raw - 20);
        }
        $rental_fin_raw = min(100, max(0, $rental_fin_raw));

        // Rental quality: location weighted highest (schools, stability matter for tenants)
        $rental_quality = $has_quality
            ? ($quality_scores['financial'] ?? 50) * 0.15
              + ($quality_scores['property'] ?? 50) * 0.20
              + ($quality_scores['location'] ?? 50) * 0.40
              + ($quality_scores['market'] ?? 50) * 0.25
            : 50;

        // Rental photo: curb appeal + exterior + overall condition
        $rental_photo = $has_photo
            ? self::compute_rental_photo_score($photo_data)
            : 50;

        // --- BRRRR Financial Component (0-100) ---
        $brrrr_fin_raw = 0;
        $cash_left  = (float) ($brrrr['cash_left_in_deal'] ?? 999999);
        $infinite   = (bool) ($brrrr['infinite_return'] ?? false);
        $brrrr_cf   = (float) ($brrrr['post_refi_monthly_cf'] ?? 0);
        $equity     = (float) ($brrrr['equity_captured'] ?? 0);
        $dscr       = (float) ($brrrr['dscr'] ?? 0);

        if ($infinite) {
            $brrrr_fin_raw += 35;
        } else {
            $total_in = (float) ($brrrr['total_cash_in'] ?? 1);
            $recovery_pct = $total_in > 0 ? (($total_in - $cash_left) / $total_in) * 100 : 0;
            $brrrr_fin_raw += min(35, max(0, $recovery_pct * 0.35));
        }
        $brrrr_fin_raw += min(25, max(0, $brrrr_cf / 20));
        $brrrr_fin_raw += min(20, max(0, $equity / 10000));
        if ($dscr !== null && $dscr > 0) {
            $brrrr_fin_raw += min(20, max(0, ($dscr - 0.8) * 50));
        }
        if ($brrrr_cf < 0) {
            $brrrr_fin_raw = max(0, $brrrr_fin_raw - 15);
        }
        $brrrr_fin_raw = min(100, max(0, $brrrr_fin_raw));

        // BRRRR quality: balanced property + location (value-add + appreciation)
        $brrrr_quality = $has_quality
            ? ($quality_scores['financial'] ?? 50) * 0.25
              + ($quality_scores['property'] ?? 50) * 0.30
              + ($quality_scores['location'] ?? 50) * 0.30
              + ($quality_scores['market'] ?? 50) * 0.15
            : 50;

        // BRRRR photo: values renovation opportunity (lower condition = more upside)
        $brrrr_photo = $has_photo
            ? self::compute_brrrr_photo_score($photo_data)
            : 50;

        // --- Compose Final Scores ---
        // When quality/photo data is available: 70/20/10 split
        // When not available: pure financial (quality/photo default to 50, which is neutral)
        $flip_score   = $flip_fin_raw * 0.70 + $flip_quality * 0.20 + $flip_photo * 0.10;
        $rental_score = $rental_fin_raw * 0.70 + $rental_quality * 0.20 + $rental_photo * 0.10;
        $brrrr_score  = $brrrr_fin_raw * 0.70 + $brrrr_quality * 0.20 + $brrrr_photo * 0.10;

        // Clamp all scores 0-100
        $flip_score   = (int) min(100, max(0, round($flip_score)));
        $rental_score = (int) min(100, max(0, round($rental_score)));
        $brrrr_score  = (int) min(100, max(0, round($brrrr_score)));

        // Determine recommendation
        $scores = [
            'flip'   => $flip_score,
            'rental' => $rental_score,
            'brrrr'  => $brrrr_score,
        ];
        arsort($scores);
        $recommended = array_key_first($scores);

        // Generate reasoning
        $reasoning = self::build_reasoning($recommended, $scores, $flip_fin, $rental, $brrrr);

        return [
            'recommended' => $recommended,
            'scores'      => [
                'flip'   => $flip_score,
                'rental' => $rental_score,
                'brrrr'  => $brrrr_score,
            ],
            'reasoning'   => $reasoning,
        ];
    }

    /**
     * Compute rental-specific photo score from Claude Vision data.
     *
     * Prioritizes tenant appeal: curb appeal, exterior condition, overall condition.
     */
    private static function compute_rental_photo_score(array $photo_data): float {
        // Use rental_appeal_score if available (v0.18.0 photo analyzer field)
        if (isset($photo_data['rental_appeal_score'])) {
            return min(100, max(0, (float) $photo_data['rental_appeal_score']));
        }

        // Fallback: compose from existing photo fields (1-10 scale → 0-100)
        $curb      = (float) ($photo_data['curb_appeal'] ?? 5);
        $exterior  = (float) ($photo_data['exterior_condition'] ?? 5);
        $overall   = (float) ($photo_data['overall_condition'] ?? 5);
        $structural_penalty = !empty($photo_data['structural_concerns']) ? 3 : 0;
        $water_penalty      = !empty($photo_data['water_damage_signs']) ? 2 : 0;
        $condition_factor   = max(0, 10 - $structural_penalty - $water_penalty);

        $raw = $curb * 0.30 + $exterior * 0.25 + $overall * 0.25 + $condition_factor * 0.20;
        return min(100, max(0, $raw * 10)); // Scale 1-10 → 0-100
    }

    /**
     * Compute BRRRR-specific photo score from Claude Vision data.
     *
     * BRRRR values renovation opportunity — lower condition = more value-add upside.
     * But structural issues are still penalized (too risky for refinance).
     */
    private static function compute_brrrr_photo_score(array $photo_data): float {
        // Use value_add_score if available (v0.18.0 photo analyzer field)
        if (isset($photo_data['value_add_score'])) {
            return min(100, max(0, (float) $photo_data['value_add_score']));
        }

        // Fallback: compose from existing fields
        $overall   = (float) ($photo_data['overall_condition'] ?? 5);
        $exterior  = (float) ($photo_data['exterior_condition'] ?? 5);
        $curb      = (float) ($photo_data['curb_appeal'] ?? 5);

        // Lower overall condition = more value-add opportunity
        $value_add = (10 - $overall) * 10; // 0-100: condition 1 = 90pts, condition 10 = 0pts
        $structural_ok = empty($photo_data['structural_concerns']) ? 25 : 0;
        $exterior_score = $exterior * 2.5;  // 0-25: decent exterior = refinanceable
        $curb_score     = $curb * 2.5;      // 0-25: curb appeal helps appraisal

        $raw = ($value_add * 0.30) + ($structural_ok * 0.25) + ($exterior_score * 0.25) + ($curb_score * 0.20);
        return min(100, max(0, $raw));
    }

    /**
     * Build human-readable recommendation reasoning.
     */
    private static function build_reasoning(
        string $recommended,
        array $scores,
        array $flip_fin,
        array $rental,
        array $brrrr
    ): string {
        switch ($recommended) {
            case 'flip':
                $profit = number_format((float) ($flip_fin['estimated_profit'] ?? 0));
                $roi = round((float) ($flip_fin['annualized_roi'] ?? 0), 1);
                return "Flip recommended: \${$profit} profit with {$roi}% annualized ROI. "
                     . "Quick capital turnover outweighs long-term hold returns.";

            case 'rental':
                $cf = number_format((float) ($rental['monthly_cash_flow'] ?? 0));
                $cap = round((float) ($rental['cap_rate'] ?? 0), 1);
                $yr5 = number_format((float) ($rental['projections'][5]['total_return'] ?? 0));
                return "Rental hold recommended: \${$cf}/mo cash flow, {$cap}% cap rate. "
                     . "5-year projected total return: \${$yr5}.";

            case 'brrrr':
                if (!empty($brrrr['infinite_return'])) {
                    $equity = number_format((float) ($brrrr['equity_captured'] ?? 0));
                    return "BRRRR recommended: infinite return — recover all capital at refinance "
                         . "plus \${$equity} equity. Redeploy capital to next deal.";
                }
                $recovery = 0;
                $total_in = (float) ($brrrr['total_cash_in'] ?? 1);
                if ($total_in > 0) {
                    $recovery = round((($total_in - (float) ($brrrr['cash_left_in_deal'] ?? 0)) / $total_in) * 100);
                }
                return "BRRRR recommended: recover {$recovery}% of capital at refinance "
                     . "with positive post-refi cash flow. Capital efficiency maximized.";

            default:
                return 'Unable to determine optimal strategy.';
        }
    }

    // ---------------------------------------------------------------
    // Multifamily Helpers
    // ---------------------------------------------------------------

    /**
     * Check if a property sub-type is a multifamily/income property.
     */
    public static function is_multifamily(string $sub_type): bool {
        if (empty($sub_type) || $sub_type === 'Single Family Residence') {
            return false;
        }
        return stripos($sub_type, 'Family') !== false || stripos($sub_type, 'Duplex') !== false;
    }

    /**
     * Apply multifamily-specific defaults to params.
     *
     * Only overrides defaults — user-configured overrides are preserved.
     * Multifamily properties have higher operating costs and different
     * financing terms than single-family residences.
     */
    private static function apply_multifamily_defaults(array $params): array {
        $mf_defaults = [
            'vacancy_rate'        => 0.08,   // 8% (higher tenant turnover)
            'insurance_rate'      => 0.010,  // 1.0% (higher liability, more units)
            'maintenance_rate'    => 0.015,  // 1.5% (more systems, common areas)
            'capex_reserve_rate'  => 0.07,   // 7% (more capex per building)
            'brrrr_refi_ltv'      => 0.70,   // 70% (tighter underwriting)
            'brrrr_refi_rate'     => 0.075,  // 7.5% (slightly higher for multifamily)
        ];

        // Check if the user has explicitly overridden defaults via the admin panel
        $user_defaults = get_option('bmn_flip_rental_defaults', '');
        $user_set = !empty($user_defaults) ? json_decode($user_defaults, true) : [];

        foreach ($mf_defaults as $key => $mf_value) {
            // Only apply multifamily default if user hasn't explicitly set this parameter
            if (!isset($user_set[$key])) {
                $params[$key] = $mf_value;
            }
        }

        return $params;
    }
}
