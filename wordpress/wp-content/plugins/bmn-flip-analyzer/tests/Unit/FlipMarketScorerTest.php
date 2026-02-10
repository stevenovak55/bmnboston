<?php
/**
 * Unit Tests for Flip_Market_Scorer.
 *
 * Tests analyze_remarks, composite score, and season scoring.
 *
 * @package BMN_Flip_Analyzer\Tests\Unit
 * @since 0.20.0
 */

namespace FlipAnalyzer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlipMarketScorerTest extends TestCase {

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
     * Build a mock property object for market scoring.
     */
    private function makeProperty(array $overrides = []): object {
        return (object) array_merge([
            'days_on_market'      => 45,
            'original_list_price' => 400000,
            'list_price'          => 380000,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // analyze_remarks()
    // ---------------------------------------------------------------

    public function test_remarks_positive_keywords(): void {
        $result = \Flip_Market_Scorer::analyze_remarks(
            'This is a handyman special, sold as-is, needs work throughout', 25
        );
        $this->assertNotEmpty($result['positive']);
        $this->assertContains('handyman', $result['positive']);
        $this->assertContains('as-is', $result['positive']);
        $this->assertContains('needs work', $result['positive']);
        $this->assertGreaterThan(0, $result['adjustment']);
    }

    public function test_remarks_negative_keywords(): void {
        $result = \Flip_Market_Scorer::analyze_remarks(
            'Completely renovated turnkey property, move-in ready with new kitchen', 25
        );
        $this->assertNotEmpty($result['negative']);
        $this->assertContains('renovated', $result['negative']);
        $this->assertContains('turnkey', $result['negative']);
        $this->assertContains('new kitchen', $result['negative']);
        $this->assertLessThan(0, $result['adjustment']);
    }

    public function test_remarks_capped_at_positive_cap(): void {
        // Many strong positive keywords → should cap at +25
        $result = \Flip_Market_Scorer::analyze_remarks(
            'foreclosure bank owned reo handyman fixer needs work contractor special as-is estate sale probate tear down teardown', 25
        );
        $this->assertEquals(25, $result['adjustment']);
    }

    public function test_remarks_capped_at_negative_cap(): void {
        // Many strong negative keywords → should cap at -25
        $result = \Flip_Market_Scorer::analyze_remarks(
            'turnkey fully updated completely renovated gut renovation brand new new construction custom built new build like new move-in ready', 25
        );
        $this->assertEquals(-25, $result['adjustment']);
    }

    public function test_remarks_empty_returns_zero_adjustment(): void {
        $result = \Flip_Market_Scorer::analyze_remarks('', 25);
        $this->assertEquals(0, $result['adjustment']);
        $this->assertEmpty($result['positive']);
        $this->assertEmpty($result['negative']);
    }

    public function test_remarks_null_returns_zero_adjustment(): void {
        $result = \Flip_Market_Scorer::analyze_remarks(null, 25);
        $this->assertEquals(0, $result['adjustment']);
    }

    public function test_remarks_mixed_signals(): void {
        // Positive + negative keywords → net effect
        $result = \Flip_Market_Scorer::analyze_remarks(
            'Property needs work but has a new roof', 25
        );
        $this->assertNotEmpty($result['positive']); // 'needs work'
        $this->assertNotEmpty($result['negative']); // 'new roof'
        // needs work = +5, new roof = -3 → net +2
        $this->assertEquals(2, $result['adjustment']);
    }

    public function test_remarks_custom_cap(): void {
        $result = \Flip_Market_Scorer::analyze_remarks(
            'foreclosure bank owned reo handyman fixer', 10
        );
        $this->assertEquals(10, $result['adjustment']);
    }

    // ---------------------------------------------------------------
    // score() composite
    // ---------------------------------------------------------------

    public function test_score_returns_expected_structure(): void {
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('remarks_signals', $result);
        $this->assertArrayHasKey('listing_dom', $result['factors']);
        $this->assertArrayHasKey('price_reduction', $result['factors']);
        $this->assertArrayHasKey('season', $result['factors']);
    }

    public function test_score_range_0_to_100(): void {
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_score_dom_high_value(): void {
        // DOM > 120 → listing_dom = 100
        $result = \Flip_Market_Scorer::score(
            $this->makeProperty(['days_on_market' => 150]),
            null
        );
        $this->assertEquals(100, $result['factors']['listing_dom']);
    }

    public function test_score_dom_low_value(): void {
        // DOM ≤ 30 → listing_dom = 30
        $result = \Flip_Market_Scorer::score(
            $this->makeProperty(['days_on_market' => 15]),
            null
        );
        $this->assertEquals(30, $result['factors']['listing_dom']);
    }

    public function test_score_price_reduction_large(): void {
        // pct = (500000-380000)/500000 = 24% > 20 → 100
        $result = \Flip_Market_Scorer::score(
            $this->makeProperty([
                'original_list_price' => 500000,
                'list_price'          => 380000,
            ]),
            null
        );
        $this->assertEquals(100, $result['factors']['price_reduction']);
    }

    public function test_score_price_reduction_none(): void {
        $result = \Flip_Market_Scorer::score(
            $this->makeProperty([
                'original_list_price' => 400000,
                'list_price'          => 400000,
            ]),
            null
        );
        $this->assertEquals(20, $result['factors']['price_reduction']);
    }

    // ---------------------------------------------------------------
    // Season scoring
    // ---------------------------------------------------------------

    public function test_season_winter_score(): void {
        // January → winter → 100
        flip_set_current_time('2026-01-15 12:00:00');
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);
        $this->assertEquals(100, $result['factors']['season']);
    }

    public function test_season_summer_score(): void {
        // July → summer → 30
        flip_set_current_time('2026-07-15 12:00:00');
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);
        $this->assertEquals(30, $result['factors']['season']);
    }

    public function test_season_fall_score(): void {
        // October → fall → 70
        flip_set_current_time('2026-10-15 12:00:00');
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);
        $this->assertEquals(70, $result['factors']['season']);
    }

    public function test_season_spring_score(): void {
        // April → spring → 40
        flip_set_current_time('2026-04-15 12:00:00');
        $result = \Flip_Market_Scorer::score($this->makeProperty(), null);
        $this->assertEquals(40, $result['factors']['season']);
    }
}
