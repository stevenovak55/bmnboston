<?php
/**
 * Unit Tests for Flip_Analyzer::calculate_financials().
 *
 * Tests the shared financial model used by the main pipeline and photo analyzer.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipFinancialsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        flip_reset_test_data();
        flip_set_current_year(2026);
    }

    protected function tearDown(): void {
        parent::tearDown();
        flip_reset_test_data();
    }

    // ---------------------------------------------------------------
    // Baseline SFR scenario
    // ---------------------------------------------------------------

    public function test_baseline_sfr_returns_expected_keys(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );

        $expected_keys = [
            'rehab_per_sqft', 'age_condition_multiplier', 'rehab_multiplier',
            'rehab_cost', 'rehab_contingency', 'contingency_rate', 'rehab_level',
            'hold_months', 'purchase_closing', 'sale_costs', 'holding_costs',
            'financing_costs', 'lead_paint_flag', 'lead_paint_cost',
            'mao', 'adjusted_mao', 'breakeven_arv', 'annualized_roi',
            'cash_profit', 'cash_roi', 'estimated_profit', 'estimated_roi',
            'cash_on_cash_roi', 'actual_tax_rate',
        ];
        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $fin, "Missing key: {$key}");
        }
    }

    public function test_baseline_sfr_rehab_calculation(): void {
        // year_built=1970, age=56, rehab_per_sqft=10+56*0.7=49.2
        // age_mult=1.0 (age>20), remarks_mult=1.0 (null remarks)
        // effective = max(2.0, 49.2*1.0*1.0) = 49.2
        // base_rehab = 1500 * 49.2 = 73800
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );

        $this->assertEqualsWithDelta(49.2, $fin['rehab_per_sqft'], 0.1);
        $this->assertEquals(1.0, $fin['age_condition_multiplier']);
        $this->assertEquals(1.0, $fin['rehab_multiplier']);
        // Rehab level: 49.2 > 35, ≤ 50 → significant, contingency=0.15
        $this->assertEquals('significant', $fin['rehab_level']);
        $this->assertEquals(0.15, $fin['contingency_rate']);
    }

    public function test_baseline_sfr_purchase_closing_includes_transfer_tax(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );

        // purchase_closing = list_price * 0.015 + list_price * 0.00456
        // = 350000 * 0.015 + 350000 * 0.00456 = 5250 + 1596 = 6846
        $expected = 350000 * 0.015 + 350000 * 0.00456;
        $this->assertEqualsWithDelta($expected, $fin['purchase_closing'], 1.0);
    }

    public function test_baseline_sfr_sale_costs(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );

        // sale_costs = ARV * (0.045 + 0.01) + ARV * 0.00456
        // = 500000 * 0.055 + 500000 * 0.00456 = 27500 + 2280 = 29780
        $expected = 500000 * (0.045 + 0.01) + 500000 * 0.00456;
        $this->assertEqualsWithDelta($expected, $fin['sale_costs'], 1.0);
    }

    public function test_baseline_sfr_cash_profit_positive_for_good_deal(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 300000, 1200, 1990, null, 30
        );
        $this->assertGreaterThan(0, $fin['cash_profit']);
    }

    // ---------------------------------------------------------------
    // Lead paint
    // ---------------------------------------------------------------

    public function test_lead_paint_added_for_pre_1978(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );
        $this->assertTrue($fin['lead_paint_flag']);
        $this->assertEquals(8000, $fin['lead_paint_cost']);
    }

    public function test_lead_paint_not_added_for_post_1978(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1985, null, 30
        );
        $this->assertFalse($fin['lead_paint_flag']);
        $this->assertEquals(0, $fin['lead_paint_cost']);
    }

    public function test_lead_paint_skipped_when_mentioned_in_remarks(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1960,
            'Property has been tested for lead paint and passed', 30
        );
        $this->assertTrue($fin['lead_paint_flag']);
        $this->assertEquals(0, $fin['lead_paint_cost']); // Skipped because remarks mention it
    }

    public function test_lead_paint_skipped_when_deleading_in_remarks(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1960,
            'Full deleading completed in 2020', 30
        );
        $this->assertTrue($fin['lead_paint_flag']);
        $this->assertEquals(0, $fin['lead_paint_cost']);
    }

    // ---------------------------------------------------------------
    // Financing (hard money)
    // ---------------------------------------------------------------

    public function test_financing_costs_calculated(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 400000, 1500, 2000, null, 30
        );

        // loan = 400000 * 0.80 = 320000
        // origination = 320000 * 0.02 = 6400
        // monthly_interest = 320000 * (0.105 / 12) = 2800
        // total_interest = 2800 * hold_months
        $loan = 400000 * 0.80;
        $origination = $loan * 0.02;
        $monthly_interest = $loan * (0.105 / 12);
        $hold_months = $fin['hold_months'];
        $expected = round($origination + $monthly_interest * $hold_months, 2);
        $this->assertEqualsWithDelta($expected, $fin['financing_costs'], 1.0);
    }

    public function test_financed_profit_less_than_cash_profit(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1990, null, 30
        );
        $this->assertLessThan($fin['cash_profit'], $fin['estimated_profit']);
    }

    public function test_cash_on_cash_roi_calculated(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 300000, 1200, 1990, null, 30
        );
        // cash_on_cash_roi should be a percentage
        $this->assertIsFloat($fin['cash_on_cash_roi']);
    }

    // ---------------------------------------------------------------
    // New construction (age ≤ 5)
    // ---------------------------------------------------------------

    public function test_new_construction_low_rehab(): void {
        // year=2023, age=3 → age_mult=0.10
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 400000, 2000, 2023, null, 30
        );
        $this->assertEquals(0.10, $fin['age_condition_multiplier']);
        // effective_per_sqft should be low (but at least $2/sqft floor)
        // rehab_per_sqft = 10 + 3*0.7 = 12.1, effective = max(2.0, 12.1*0.10*1.0) = max(2.0, 1.21) = 2.0
        // rehab_cost = 2000 * 2.0 * (1 + contingency) = moderate
        $this->assertLessThan(20000, $fin['rehab_cost']);
    }

    // ---------------------------------------------------------------
    // Distressed property (remarks keywords)
    // ---------------------------------------------------------------

    public function test_distressed_remarks_increase_rehab(): void {
        $fin_normal = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970, null, 30
        );
        $fin_distressed = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 1970,
            'needs work, foundation issues, water damage',
            30
        );
        $this->assertGreaterThan($fin_normal['rehab_cost'], $fin_distressed['rehab_cost']);
        $this->assertGreaterThan(1.0, $fin_distressed['rehab_multiplier']);
    }

    // ---------------------------------------------------------------
    // Breakeven ARV
    // ---------------------------------------------------------------

    public function test_breakeven_arv_below_estimated_arv_for_good_deal(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 300000, 1200, 1990, null, 30
        );
        $this->assertLessThan(500000, $fin['breakeven_arv']);
    }

    public function test_breakeven_arv_formula(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 2000, null, 30
        );
        // breakeven = (list + rehab + closing + holding + financing) / (1 - sale_cost_pct)
        $sale_pct = 0.045 + 0.01 + 0.00456;
        $expected = (350000 + $fin['rehab_cost'] + $fin['purchase_closing']
                    + $fin['holding_costs'] + $fin['financing_costs'])
                    / (1 - $sale_pct);
        $this->assertEqualsWithDelta($expected, $fin['breakeven_arv'], 1.0);
    }

    // ---------------------------------------------------------------
    // Annualized ROI
    // ---------------------------------------------------------------

    public function test_annualized_roi_positive_for_good_deal(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 280000, 1200, 1990, null, 30
        );
        $this->assertGreaterThan(0, $fin['annualized_roi']);
    }

    // ---------------------------------------------------------------
    // Custom tax rate
    // ---------------------------------------------------------------

    public function test_custom_tax_rate_overrides_default(): void {
        $fin_default = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 2000, null, 30, null
        );
        $fin_custom = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 2000, null, 30, 0.02
        );

        $this->assertEquals(0.013, $fin_default['actual_tax_rate']);
        $this->assertEquals(0.02, $fin_custom['actual_tax_rate']);
        // Higher tax rate → higher holding costs
        $this->assertGreaterThan($fin_default['holding_costs'], $fin_custom['holding_costs']);
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    public function test_zero_arv_returns_negative_profit(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            0, 350000, 1500, 2000, null, 30
        );
        $this->assertLessThan(0, $fin['cash_profit']);
    }

    public function test_zero_sqft_minimal_rehab(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 0, 2000, null, 30
        );
        // base_rehab = 0 * anything = 0, but lead paint might still apply
        $this->assertLessThanOrEqual(8000, $fin['rehab_cost']); // At most lead paint
    }

    public function test_mao_calculation(): void {
        $fin = \Flip_Analyzer::calculate_financials(
            500000, 350000, 1500, 2000, null, 30
        );
        // mao = ARV * 0.70 - rehab_cost
        $expected = 500000 * 0.70 - $fin['rehab_cost'];
        $this->assertEqualsWithDelta($expected, $fin['mao'], 1.0);
    }
}
