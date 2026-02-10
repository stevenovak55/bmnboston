<?php
/**
 * Unit Tests for Flip_Disqualifier.
 *
 * Tests universal/flip-specific disqualifiers, distress signals,
 * rental/BRRRR viability, and post-calc disqualifiers.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipDisqualifierTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        flip_reset_test_data();
        flip_set_current_year(2026);
    }

    protected function tearDown(): void {
        parent::tearDown();
        flip_reset_test_data();
    }

    /**
     * Build a mock property object.
     */
    private function makeProperty(array $overrides = []): object {
        return (object) array_merge([
            'list_price'          => 400000,
            'building_area_total' => 1500,
            'year_built'          => 1970,
            'days_on_market'      => 30,
            'property_sub_type'   => 'Single Family Residence',
        ], $overrides);
    }

    /**
     * Build a mock ARV data array.
     */
    private function makeArvData(array $overrides = []): array {
        return array_merge([
            'estimated_arv'       => 500000,
            'comp_count'          => 5,
            'market_strength'     => 'balanced',
            'ceiling_pct'         => 80,
            'neighborhood_ceiling' => 600000,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // check_universal_disqualifiers()
    // ---------------------------------------------------------------

    public function test_universal_dq_passes_normal_property(): void {
        $result = \Flip_Disqualifier::check_universal_disqualifiers(
            $this->makeProperty(),
            $this->makeArvData()
        );
        $this->assertNull($result);
    }

    public function test_universal_dq_price_below_minimum(): void {
        $result = \Flip_Disqualifier::check_universal_disqualifiers(
            $this->makeProperty(['list_price' => 80000]),
            $this->makeArvData()
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('below minimum', $result);
    }

    public function test_universal_dq_zero_comps(): void {
        $result = \Flip_Disqualifier::check_universal_disqualifiers(
            $this->makeProperty(),
            $this->makeArvData(['comp_count' => 0])
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('No comparable', $result);
    }

    public function test_universal_dq_sqft_too_small(): void {
        $result = \Flip_Disqualifier::check_universal_disqualifiers(
            $this->makeProperty(['building_area_total' => 500]),
            $this->makeArvData()
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('too small', $result);
    }

    // ---------------------------------------------------------------
    // check_flip_disqualifiers()
    // ---------------------------------------------------------------

    public function test_flip_dq_passes_normal_property(): void {
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(),
            $this->makeArvData(),
            null,
            null
        );
        $this->assertNull($result);
    }

    public function test_flip_dq_new_construction_le5(): void {
        // year=2023, age=3 → auto-DQ (no distress signals)
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            null,
            null
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('Recent construction', $result);
    }

    public function test_flip_dq_new_construction_with_distress_passes(): void {
        // year=2023 but has distress signals → should NOT be DQ'd
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'foreclosure sale, bank owned property',
            null
        );
        $this->assertNull($result);
    }

    public function test_flip_dq_new_construction_with_poor_condition_passes(): void {
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            null,
            'Poor'
        );
        $this->assertNull($result);
    }

    public function test_flip_dq_pristine_condition_young_property(): void {
        // age=10 (<15) + "Excellent" condition + no distress → DQ
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2016]),
            $this->makeArvData(),
            null,
            'Excellent'
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('condition', $result);
    }

    public function test_flip_dq_pristine_condition_old_property_passes(): void {
        // age=30 (>15) + "Excellent" condition → NOT DQ'd (age > 15 threshold)
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 1996]),
            $this->makeArvData(),
            null,
            'Excellent'
        );
        $this->assertNull($result);
    }

    public function test_flip_dq_price_too_close_to_arv(): void {
        // balanced market: max_ratio = 0.85, price/arv = 440000/500000 = 0.88 > 0.85
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['list_price' => 440000]),
            $this->makeArvData(['estimated_arv' => 500000]),
            null,
            null
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('too close to ARV', $result);
    }

    public function test_flip_dq_ceiling_exceeded(): void {
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(),
            $this->makeArvData(['ceiling_pct' => 125]),
            null,
            null
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('120%', $result);
    }

    // ---------------------------------------------------------------
    // has_distress_signals() — tested indirectly through check_flip_disqualifiers()
    // ---------------------------------------------------------------

    public function test_distress_strong_keyword_foreclosure(): void {
        // New construction (age=3) with foreclosure → passes DQ
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'Property is in foreclosure, must sell quickly',
            null
        );
        $this->assertNull($result); // Distress bypasses new construction DQ
    }

    public function test_distress_strong_keyword_bank_owned(): void {
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'Bank owned REO property',
            null
        );
        $this->assertNull($result);
    }

    public function test_distress_weak_keyword_negated(): void {
        // "no need for a fixer" → negated → no distress → DQ stands
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'No need for a fixer upper - this home is turn key ready',
            null
        );
        $this->assertNotNull($result); // Should still be DQ'd
    }

    public function test_distress_empty_remarks(): void {
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            '',
            null
        );
        $this->assertNotNull($result); // No distress → new construction DQ
    }

    public function test_distress_weak_keyword_unegated(): void {
        // "fixer" without negation → distress detected → DQ bypassed
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'This is a fixer upper opportunity',
            null
        );
        $this->assertNull($result);
    }

    public function test_distress_word_boundary_check(): void {
        // "prefix_fixer_suffix" should not match on word boundary
        // Actually "fixers" would match because 's' is alpha → boundary fails
        // But "a fixer" should match since space is not alpha
        $result = \Flip_Disqualifier::check_flip_disqualifiers(
            $this->makeProperty(['year_built' => 2023]),
            $this->makeArvData(),
            'Definitely a fixer upper project',
            null
        );
        $this->assertNull($result); // distress detected
    }

    // ---------------------------------------------------------------
    // check_rental_viable()
    // ---------------------------------------------------------------

    public function test_rental_viable_good_numbers(): void {
        $result = \Flip_Disqualifier::check_rental_viable([
            'cap_rate'          => 5.0,
            'monthly_cash_flow' => 200,
        ]);
        $this->assertTrue($result);
    }

    public function test_rental_viable_null_input(): void {
        $result = \Flip_Disqualifier::check_rental_viable(null);
        $this->assertFalse($result);
    }

    public function test_rental_viable_low_cap_rate(): void {
        $result = \Flip_Disqualifier::check_rental_viable([
            'cap_rate'          => 2.0,
            'monthly_cash_flow' => 500,
        ]);
        $this->assertFalse($result);
    }

    public function test_rental_viable_negative_cash_flow_beyond_threshold(): void {
        $result = \Flip_Disqualifier::check_rental_viable([
            'cap_rate'          => 5.0,
            'monthly_cash_flow' => -300,
        ]);
        $this->assertFalse($result);
    }

    public function test_rental_viable_slightly_negative_cash_flow_ok(): void {
        // monthly_cf > -200 (allows slightly negative)
        $result = \Flip_Disqualifier::check_rental_viable([
            'cap_rate'          => 4.0,
            'monthly_cash_flow' => -150,
        ]);
        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // check_brrrr_viable()
    // ---------------------------------------------------------------

    public function test_brrrr_viable_good_numbers(): void {
        $result = \Flip_Disqualifier::check_brrrr_viable([
            'dscr'              => 1.2,
            'cash_left_in_deal' => 30000,
            'total_cash_in'     => 50000,
        ]);
        $this->assertTrue($result);
    }

    public function test_brrrr_viable_null_input(): void {
        $result = \Flip_Disqualifier::check_brrrr_viable(null);
        $this->assertFalse($result);
    }

    public function test_brrrr_viable_low_dscr(): void {
        $result = \Flip_Disqualifier::check_brrrr_viable([
            'dscr'              => 0.5,
            'cash_left_in_deal' => 30000,
            'total_cash_in'     => 50000,
        ]);
        $this->assertFalse($result);
    }

    public function test_brrrr_viable_too_much_cash_left(): void {
        // cash_left >= total_in * 2 → not viable
        $result = \Flip_Disqualifier::check_brrrr_viable([
            'dscr'              => 1.2,
            'cash_left_in_deal' => 150000,
            'total_cash_in'     => 50000,
        ]);
        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // check_post_calc_disqualifiers()
    // ---------------------------------------------------------------

    public function test_post_calc_passes_good_deal(): void {
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            50000, 25.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'medium'
        );
        $this->assertNull($result);
    }

    public function test_post_calc_dq_low_profit(): void {
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            15000, 25.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'medium'
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('profit', $result);
    }

    public function test_post_calc_dq_low_roi(): void {
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            50000, 8.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'medium'
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('ROI', $result);
    }

    public function test_post_calc_low_confidence_stricter(): void {
        // 'low' confidence → 1.25x factor → min_profit = 25000*1.25 = 31250
        // profit=30000 < 31250 → DQ
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            30000, 25.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'low'
        );
        $this->assertNotNull($result);
        $this->assertStringContainsString('low ARV confidence', $result);
    }

    public function test_post_calc_none_confidence_strictest(): void {
        // 'none' confidence → 1.5x factor → min_profit = 25000*1.5 = 37500
        // profit=36000 < 37500 → DQ
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            36000, 25.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'none'
        );
        $this->assertNotNull($result);
    }

    public function test_post_calc_high_confidence_no_penalty(): void {
        // 'high' confidence → 1.0x factor → min_profit stays 25000
        $result = \Flip_Disqualifier::check_post_calc_disqualifiers(
            26000, 16.0,
            ['min_profit' => 25000, 'min_roi' => 15, 'market_strength' => 'balanced'],
            'high'
        );
        $this->assertNull($result);
    }
}
