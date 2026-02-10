<?php
/**
 * Unit Tests for Flip_Property_Scorer.
 *
 * Tests lot_size, expansion_potential, existing_sqft, renovation_need scoring
 * through the public score() and get_expansion_category() methods.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipPropertyScorerTest extends TestCase {

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
     * Build a mock property object for property scoring.
     */
    private function makeProperty(array $overrides = []): object {
        return (object) array_merge([
            'building_area_total' => 1500,
            'lot_size_acres'      => 0.5,
            'year_built'          => 1970,
            'property_sub_type'   => 'Single Family Residence',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // score() integration
    // ---------------------------------------------------------------

    public function test_score_returns_expected_structure(): void {
        $result = \Flip_Property_Scorer::score($this->makeProperty());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('lot_size', $result['factors']);
        $this->assertArrayHasKey('expansion_potential', $result['factors']);
        $this->assertArrayHasKey('existing_sqft', $result['factors']);
        $this->assertArrayHasKey('renovation_need', $result['factors']);
    }

    public function test_score_range_0_to_100(): void {
        $result = \Flip_Property_Scorer::score($this->makeProperty());
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    // ---------------------------------------------------------------
    // Lot size sub-factor (through score)
    // ---------------------------------------------------------------

    public function test_lot_size_large_lot(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['lot_size_acres' => 2.5])
        );
        $this->assertEquals(100, $result['factors']['lot_size']);
    }

    public function test_lot_size_standard_quarter_acre(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['lot_size_acres' => 0.25])
        );
        $this->assertEquals(60, $result['factors']['lot_size']);
    }

    public function test_lot_size_zero_returns_30(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['lot_size_acres' => 0])
        );
        $this->assertEquals(30, $result['factors']['lot_size']);
    }

    // ---------------------------------------------------------------
    // Expansion potential sub-factor
    // ---------------------------------------------------------------

    public function test_expansion_condo_capped_at_40(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty([
                'property_sub_type'   => 'Condominium',
                'lot_size_acres'      => 1.0,
                'building_area_total' => 800,
            ])
        );
        $this->assertLessThanOrEqual(40, $result['factors']['expansion_potential']);
    }

    public function test_expansion_townhouse_capped_at_40(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty([
                'property_sub_type'   => 'Townhouse',
                'lot_size_acres'      => 0.5,
                'building_area_total' => 1200,
            ])
        );
        $this->assertLessThanOrEqual(40, $result['factors']['expansion_potential']);
    }

    public function test_expansion_sfr_not_capped(): void {
        // Large lot, small house → high expansion potential
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty([
                'property_sub_type'   => 'Single Family Residence',
                'lot_size_acres'      => 2.0,
                'building_area_total' => 1000,
            ])
        );
        // lot_sqft = 2.0 * 43560 = 87120, ratio = 87.12, room = 86120
        $this->assertGreaterThan(40, $result['factors']['expansion_potential']);
    }

    // ---------------------------------------------------------------
    // Existing sqft sub-factor
    // ---------------------------------------------------------------

    public function test_sqft_large_home(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['building_area_total' => 4500])
        );
        $this->assertEquals(100, $result['factors']['existing_sqft']);
    }

    public function test_sqft_zero_returns_30(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['building_area_total' => 0])
        );
        $this->assertEquals(30, $result['factors']['existing_sqft']);
    }

    // ---------------------------------------------------------------
    // Renovation need sub-factor
    // ---------------------------------------------------------------

    public function test_renovation_need_sweet_spot(): void {
        // age=50 (1976), 41-70 → 95
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['year_built' => 1976])
        );
        $this->assertEquals(95, $result['factors']['renovation_need']);
    }

    public function test_renovation_need_brand_new(): void {
        // age=2 (2024), ≤5 → 5
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['year_built' => 2024])
        );
        $this->assertEquals(5, $result['factors']['renovation_need']);
    }

    public function test_renovation_need_year_zero_returns_50(): void {
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['year_built' => 0])
        );
        $this->assertEquals(50, $result['factors']['renovation_need']);
    }

    public function test_renovation_need_very_old(): void {
        // age=130 (1896), > 100 → 70
        $result = \Flip_Property_Scorer::score(
            $this->makeProperty(['year_built' => 1896])
        );
        $this->assertEquals(70, $result['factors']['renovation_need']);
    }

    // ---------------------------------------------------------------
    // get_expansion_category()
    // ---------------------------------------------------------------

    public function test_expansion_category_excellent(): void {
        // lot_sqft/sqft >= 10 → excellent
        $category = \Flip_Property_Scorer::get_expansion_category(2.0, 1000);
        // lot_sqft = 87120, ratio = 87.12
        $this->assertEquals('excellent', $category);
    }

    public function test_expansion_category_limited(): void {
        // Small lot, large house
        $category = \Flip_Property_Scorer::get_expansion_category(0.1, 3000);
        // lot_sqft = 4356, ratio = 1.45
        $this->assertEquals('limited', $category);
    }

    public function test_expansion_category_unknown_zero_lot(): void {
        $category = \Flip_Property_Scorer::get_expansion_category(0, 1500);
        $this->assertEquals('unknown', $category);
    }
}
