<?php
/**
 * Unit Tests for Flip_Analyzer helper methods.
 *
 * Tests the core financial formulas: rehab per sqft, age condition multiplier,
 * remarks rehab multiplier, adaptive thresholds, and deal risk grade.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipAnalyzerHelpersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        flip_reset_test_data();
        // Fix year to 2026 for deterministic age calculations
        flip_set_current_year(2026);
    }

    protected function tearDown(): void {
        parent::tearDown();
        flip_reset_test_data();
    }

    // ---------------------------------------------------------------
    // get_rehab_per_sqft() — continuous formula: clamp(10 + age × 0.7, 5, 65)
    // ---------------------------------------------------------------

    public function test_rehab_per_sqft_year_built_zero_returns_45(): void {
        $result = \Flip_Analyzer::get_rehab_per_sqft(0);
        $this->assertSame(45.0, $result);
    }

    public function test_rehab_per_sqft_new_construction_5yr(): void {
        // age = 2026 - 2021 = 5, formula: 10 + 5*0.7 = 13.5
        $result = \Flip_Analyzer::get_rehab_per_sqft(2021);
        $this->assertEqualsWithDelta(13.5, $result, 0.01);
    }

    public function test_rehab_per_sqft_10yr(): void {
        // age = 10, formula: 10 + 10*0.7 = 17.0
        $result = \Flip_Analyzer::get_rehab_per_sqft(2016);
        $this->assertEqualsWithDelta(17.0, $result, 0.01);
    }

    public function test_rehab_per_sqft_50yr(): void {
        // age = 50, formula: 10 + 50*0.7 = 45.0
        $result = \Flip_Analyzer::get_rehab_per_sqft(1976);
        $this->assertEqualsWithDelta(45.0, $result, 0.01);
    }

    public function test_rehab_per_sqft_capped_at_65(): void {
        // age = 100, formula: 10 + 100*0.7 = 80 → clamped to 65
        $result = \Flip_Analyzer::get_rehab_per_sqft(1926);
        $this->assertSame(65.0, $result);
    }

    public function test_rehab_per_sqft_floor_at_5(): void {
        // age = 0 (built this year), formula: 10 + 0*0.7 = 10 (above floor)
        $result = \Flip_Analyzer::get_rehab_per_sqft(2026);
        $this->assertEqualsWithDelta(10.0, $result, 0.01);
    }

    // ---------------------------------------------------------------
    // get_age_condition_multiplier() — step function
    // ---------------------------------------------------------------

    public function test_age_multiplier_year_built_zero_returns_1(): void {
        $result = \Flip_Analyzer::get_age_condition_multiplier(0);
        $this->assertSame(1.0, $result);
    }

    public function test_age_multiplier_brand_new_le5(): void {
        // age = 3 (2023), ≤5 → 0.10
        $result = \Flip_Analyzer::get_age_condition_multiplier(2023);
        $this->assertSame(0.10, $result);
    }

    public function test_age_multiplier_boundary_5yr(): void {
        // age = 5 (2021), ≤5 → 0.10
        $result = \Flip_Analyzer::get_age_condition_multiplier(2021);
        $this->assertSame(0.10, $result);
    }

    public function test_age_multiplier_6_to_10(): void {
        // age = 8 (2018), ≤10 → 0.30
        $result = \Flip_Analyzer::get_age_condition_multiplier(2018);
        $this->assertSame(0.30, $result);
    }

    public function test_age_multiplier_11_to_15(): void {
        // age = 13 (2013), ≤15 → 0.50
        $result = \Flip_Analyzer::get_age_condition_multiplier(2013);
        $this->assertSame(0.50, $result);
    }

    public function test_age_multiplier_16_to_20(): void {
        // age = 18 (2008), ≤20 → 0.75
        $result = \Flip_Analyzer::get_age_condition_multiplier(2008);
        $this->assertSame(0.75, $result);
    }

    public function test_age_multiplier_21_plus(): void {
        // age = 30 (1996), >20 → 1.0
        $result = \Flip_Analyzer::get_age_condition_multiplier(1996);
        $this->assertSame(1.0, $result);
    }

    // ---------------------------------------------------------------
    // get_remarks_rehab_multiplier()
    // ---------------------------------------------------------------

    public function test_remarks_null_returns_1(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier(null);
        $this->assertSame(1.0, $result);
    }

    public function test_remarks_empty_returns_1(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier('');
        $this->assertSame(1.0, $result);
    }

    public function test_remarks_cost_reducer_new_roof(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier('Property has a new roof installed 2025');
        // 1.0 + (-0.08) = 0.92
        $this->assertEqualsWithDelta(0.92, $result, 0.01);
    }

    public function test_remarks_cost_increaser_needs_work(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier('Property needs work throughout');
        // 1.0 + 0.10 = 1.10
        $this->assertEqualsWithDelta(1.10, $result, 0.01);
    }

    public function test_remarks_multiple_keywords_stack(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier(
            'Property needs work, foundation issues, water damage throughout'
        );
        // 1.0 + 0.10 (needs work) + 0.20 (foundation issues) + 0.10 (water damage) = 1.40
        $this->assertEqualsWithDelta(1.40, $result, 0.01);
    }

    public function test_remarks_clamped_at_max_1_5(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier(
            'needs work, needs renovation, foundation issues, water damage, deferred maintenance, knob and tube, asbestos'
        );
        // Sum exceeds 0.5 delta → clamped to 1.5
        $this->assertSame(1.5, min(1.5, $result));
        $this->assertLessThanOrEqual(1.5, $result);
    }

    public function test_remarks_clamped_at_min_0_5(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier(
            'new roof, new furnace, new hvac, updated electrical, new plumbing, new windows, new siding, new kitchen, new bath, updated kitchen, updated bath'
        );
        // Sum of all reducers exceeds -0.5 delta → clamped to 0.5
        $this->assertGreaterThanOrEqual(0.5, $result);
    }

    public function test_remarks_case_insensitive(): void {
        $result = \Flip_Analyzer::get_remarks_rehab_multiplier('NEW ROOF installed recently');
        $this->assertEqualsWithDelta(0.92, $result, 0.01);
    }

    // ---------------------------------------------------------------
    // get_adaptive_thresholds()
    // ---------------------------------------------------------------

    public function test_adaptive_thresholds_balanced_market(): void {
        $thresholds = \Flip_Analyzer::get_adaptive_thresholds('balanced', 1.0, 'medium');
        // multiplier = 2.5 - 1.5*1.0 = 1.0; balanced bounds: profit [25000,25000], roi [15,15]
        $this->assertEquals(25000, $thresholds['min_profit']);
        $this->assertEquals(15.0, $thresholds['min_roi']);
        $this->assertEquals(0.85, $thresholds['max_price_arv']);
    }

    public function test_adaptive_thresholds_very_hot_market(): void {
        $thresholds = \Flip_Analyzer::get_adaptive_thresholds('very_hot', 1.07, 'high');
        // multiplier = 2.5 - 1.5*1.07 = 2.5 - 1.605 = 0.895
        // raw_profit = 25000 * 0.895 = 22375, clamped to [10000,20000] → 20000
        // raw_roi = 15 * 0.895 = 13.425, clamped to [5,8] → 8
        $this->assertEquals(20000, $thresholds['min_profit']);
        $this->assertEquals(8.0, $thresholds['min_roi']);
        $this->assertEquals(0.92, $thresholds['max_price_arv']);
    }

    public function test_adaptive_thresholds_cold_market(): void {
        $thresholds = \Flip_Analyzer::get_adaptive_thresholds('cold', 0.90, 'medium');
        // multiplier = 2.5 - 1.5*0.90 = 2.5 - 1.35 = 1.15
        // raw_profit = 25000 * 1.15 = 28750, clamped to [28000,35000] → 28750 → round → 28750
        $this->assertGreaterThanOrEqual(28000, $thresholds['min_profit']);
        $this->assertLessThanOrEqual(35000, $thresholds['min_profit']);
        $this->assertEquals(0.78, $thresholds['max_price_arv']);
    }

    public function test_adaptive_thresholds_low_confidence_guard(): void {
        // Low confidence + hot market should fall back to balanced bounds
        $thresholds = \Flip_Analyzer::get_adaptive_thresholds('hot', 1.03, 'low');
        // use_tier becomes 'balanced' because of low confidence
        // balanced bounds: profit [25000,25000], roi [15,15]
        $this->assertEquals(25000, $thresholds['min_profit']);
        $this->assertEquals(15.0, $thresholds['min_roi']);
        // But max_price_arv still uses the actual market_strength
        $this->assertEquals(0.90, $thresholds['max_price_arv']);
    }

    public function test_adaptive_thresholds_multiplier_clamped(): void {
        // Very extreme values should clamp the multiplier
        $thresholds = \Flip_Analyzer::get_adaptive_thresholds('balanced', 2.0, 'high');
        // multiplier = 2.5 - 1.5*2.0 = -0.5 → clamped to 0.4
        $this->assertEquals(0.4, $thresholds['multiplier']);
    }

    // ---------------------------------------------------------------
    // calculate_deal_risk_grade()
    // ---------------------------------------------------------------

    public function test_risk_grade_A_strong_deal(): void {
        // High confidence, big margin, consistent comps, fast market, many comps
        $comps = $this->makeComps([300, 310, 305, 308, 302, 307, 304, 309]);
        $grade = \Flip_Analyzer::calculate_deal_risk_grade(
            'high', 350000, 500000, $comps, 10, 8
        );
        $this->assertEquals('A', $grade);
    }

    public function test_risk_grade_F_weak_deal(): void {
        // No confidence, ARV below breakeven, inconsistent comps, slow market
        $comps = $this->makeComps([100, 500, 200]);
        $grade = \Flip_Analyzer::calculate_deal_risk_grade(
            'none', 600000, 400000, $comps, 120, 2
        );
        $this->assertEquals('F', $grade);
    }

    public function test_risk_grade_boundary_B(): void {
        // Medium confidence, moderate margin, decent comps
        $comps = $this->makeComps([290, 310, 300, 305, 295]);
        $grade = \Flip_Analyzer::calculate_deal_risk_grade(
            'medium', 400000, 500000, $comps, 25, 5
        );
        // composite around 65-80 depending on exact calculations
        $this->assertContains($grade, ['A', 'B']);
    }

    public function test_risk_grade_comp_consistency_with_uniform_comps(): void {
        // All comps at same price → CV = 0 → consistency_score = 100
        $comps = $this->makeComps([300, 300, 300, 300, 300]);
        $grade = \Flip_Analyzer::calculate_deal_risk_grade(
            'high', 300000, 500000, $comps, 15, 5
        );
        $this->assertEquals('A', $grade);
    }

    public function test_risk_grade_fewer_than_3_comps_uses_default_consistency(): void {
        // <3 comps → consistency_score defaults to 50
        $comps = $this->makeComps([300, 310]);
        $grade = \Flip_Analyzer::calculate_deal_risk_grade(
            'high', 350000, 500000, $comps, 15, 8
        );
        // Should still be reasonable grade
        $this->assertContains($grade, ['A', 'B', 'C']);
    }

    /**
     * Helper: create mock comp objects with adjusted_ppsf values.
     */
    private function makeComps(array $ppsf_values): array {
        return array_map(function ($ppsf) {
            return (object) ['adjusted_ppsf' => $ppsf, 'ppsf' => $ppsf];
        }, $ppsf_values);
    }
}
