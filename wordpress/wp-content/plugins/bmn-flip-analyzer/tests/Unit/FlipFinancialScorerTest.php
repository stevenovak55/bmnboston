<?php
/**
 * Unit Tests for Flip_Financial_Scorer.
 *
 * Tests the 40%-weight financial scorer with 4 sub-factors.
 * Since sub-factor methods are private, we test through the public score() method
 * using carefully chosen inputs that isolate each factor.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipFinancialScorerTest extends TestCase {

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
     * Build a mock property object with financial scoring fields.
     */
    private function makeProperty(array $overrides = []): object {
        return (object) array_merge([
            'list_price'          => 350000,
            'price_per_sqft'      => 200,
            'original_list_price' => 350000,
            'days_on_market'      => 30,
        ], $overrides);
    }

    /**
     * Build a mock ARV data array.
     */
    private function makeArvData(array $overrides = []): array {
        return array_merge([
            'estimated_arv' => 500000,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // score() integration — returns expected structure
    // ---------------------------------------------------------------

    public function test_score_returns_expected_structure(): void {
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(),
            $this->makeArvData(),
            300
        );

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('price_vs_arv', $result['factors']);
        $this->assertArrayHasKey('ppsf_vs_neighborhood', $result['factors']);
        $this->assertArrayHasKey('price_reduction', $result['factors']);
        $this->assertArrayHasKey('dom_motivation', $result['factors']);
    }

    public function test_score_range_0_to_100(): void {
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(),
            $this->makeArvData(),
            300
        );
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    // ---------------------------------------------------------------
    // Price/ARV ratio sub-factor (through score)
    // ---------------------------------------------------------------

    public function test_price_arv_ratio_deep_value(): void {
        // ratio = 300000/500000 = 0.60 < 0.65 → 100
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['list_price' => 300000, 'price_per_sqft' => 200,
                                 'original_list_price' => 300000, 'days_on_market' => 0]),
            $this->makeArvData(['estimated_arv' => 500000]),
            200 // ppsf == avg → score 20
        );
        $this->assertEquals(100, $result['factors']['price_vs_arv']);
    }

    public function test_price_arv_ratio_thin_margin(): void {
        // ratio = 420000/500000 = 0.84 ≥ 0.80 → 20
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['list_price' => 420000]),
            $this->makeArvData(['estimated_arv' => 500000]),
            300
        );
        $this->assertEquals(20, $result['factors']['price_vs_arv']);
    }

    public function test_price_arv_ratio_zero_arv(): void {
        // ARV=0 → score 0
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['list_price' => 350000]),
            $this->makeArvData(['estimated_arv' => 0]),
            300
        );
        $this->assertEquals(0, $result['factors']['price_vs_arv']);
    }

    // ---------------------------------------------------------------
    // PPSF vs neighborhood sub-factor
    // ---------------------------------------------------------------

    public function test_ppsf_significantly_below_avg(): void {
        // ppsf=150, avg=250: pct_below = (250-150)/250 * 100 = 40% > 25 → 100
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['price_per_sqft' => 150]),
            $this->makeArvData(),
            250
        );
        $this->assertEquals(100, $result['factors']['ppsf_vs_neighborhood']);
    }

    public function test_ppsf_at_neighborhood_avg(): void {
        // ppsf=300, avg=300: pct_below = 0% ≤ 10 → 20
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['price_per_sqft' => 300]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(20, $result['factors']['ppsf_vs_neighborhood']);
    }

    public function test_ppsf_zero_avg_returns_neutral(): void {
        // avg_ppsf=0 → neutral score 50
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['price_per_sqft' => 200]),
            $this->makeArvData(),
            0
        );
        $this->assertEquals(50, $result['factors']['ppsf_vs_neighborhood']);
    }

    public function test_ppsf_zero_property_ppsf_returns_penalty(): void {
        // ppsf=0 → penalty score 30
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['price_per_sqft' => 0]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(30, $result['factors']['ppsf_vs_neighborhood']);
    }

    // ---------------------------------------------------------------
    // Price reduction sub-factor
    // ---------------------------------------------------------------

    public function test_price_reduction_large(): void {
        // original=500000, current=400000: pct = 20% > 15 → 100
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty([
                'original_list_price' => 500000,
                'list_price'          => 400000,
            ]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(100, $result['factors']['price_reduction']);
    }

    public function test_price_reduction_none(): void {
        // original == current → 20
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty([
                'original_list_price' => 400000,
                'list_price'          => 400000,
            ]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(20, $result['factors']['price_reduction']);
    }

    public function test_price_reduction_zero_original(): void {
        // original=0 → 20
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty([
                'original_list_price' => 0,
                'list_price'          => 350000,
            ]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(20, $result['factors']['price_reduction']);
    }

    // ---------------------------------------------------------------
    // DOM motivation sub-factor
    // ---------------------------------------------------------------

    public function test_dom_high_motivation(): void {
        // DOM > 90 → 100
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['days_on_market' => 120]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(100, $result['factors']['dom_motivation']);
    }

    public function test_dom_low_motivation(): void {
        // DOM ≤ 30 → 20
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['days_on_market' => 15]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(20, $result['factors']['dom_motivation']);
    }

    public function test_dom_moderate(): void {
        // DOM > 60 → 70
        $result = \Flip_Financial_Scorer::score(
            $this->makeProperty(['days_on_market' => 75]),
            $this->makeArvData(),
            300
        );
        $this->assertEquals(70, $result['factors']['dom_motivation']);
    }
}
