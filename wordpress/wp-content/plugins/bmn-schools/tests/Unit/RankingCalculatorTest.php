<?php
/**
 * Ranking Calculator Unit Tests
 *
 * Tests for the BMN_Schools_Ranking_Calculator class, specifically focusing on:
 * - Year rollover bug prevention (Critical Pitfall #2)
 * - Percentile calculation
 * - Score weighting
 *
 * @package BMN_Schools\Tests\Unit
 * @since 0.6.38
 */

namespace BMN_Schools\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/BMN_Schools_Unit_TestCase.php';
require_once dirname(dirname(__DIR__)) . '/includes/class-ranking-calculator.php';

/**
 * Ranking Calculator Test Class
 */
class RankingCalculatorTest extends BMN_Schools_Unit_TestCase {

    /**
     * Test that get_latest_data_year returns max year from database, not current year.
     *
     * Critical Pitfall #2: Year Rollover Bug
     *
     * On January 1, date('Y') returns the new year but ranking data is still from
     * the previous year. This test ensures we query MAX(year) from the database.
     */
    public function test_get_latest_data_year_returns_max_from_database() {
        // Mock database to return 2025 as max year
        $this->mockGetVar('MAX(year)', 2025);

        $year = \BMN_Schools_Ranking_Calculator::get_latest_data_year();

        $this->assertEquals(2025, $year, 'Should return max year from database (2025)');
    }

    /**
     * Test that get_latest_data_year does NOT return the current PHP year.
     *
     * This specifically tests the bug that occurred on January 1, 2026 when
     * date('Y') returned 2026 but all ranking data was from 2025.
     */
    public function test_get_latest_data_year_does_not_use_php_date() {
        // Mock database to return 2025 (simulating Jan 1, 2026 scenario)
        $this->mockGetVar('MAX(year)', 2025);

        $year = \BMN_Schools_Ranking_Calculator::get_latest_data_year();
        $current_year = (int) date('Y');

        // If current year is 2026 or later, the year from database should be different
        if ($current_year >= 2026) {
            $this->assertNotEquals($current_year, $year,
                'Should NOT return current PHP year when database has older data');
        }

        $this->assertEquals(2025, $year,
            'Should return 2025 from database regardless of current date');
    }

    /**
     * Test fallback when no ranking data exists.
     *
     * When MAX(year) returns NULL (no rankings), should fallback to previous year.
     */
    public function test_get_latest_data_year_fallback_when_no_data() {
        // Mock database to return NULL (no rankings)
        $this->mockGetVar('MAX(year)', null);

        $year = \BMN_Schools_Ranking_Calculator::get_latest_data_year();
        $expected_fallback = (int) date('Y') - 1;

        $this->assertEquals($expected_fallback, $year,
            'Should fallback to current year - 1 when no ranking data exists');
    }

    /**
     * Test that constructor uses get_latest_data_year by default.
     */
    public function test_constructor_uses_latest_data_year() {
        // Mock database to return 2025
        $this->mockGetVar('MAX(year)', 2025);

        $calculator = new \BMN_Schools_Ranking_Calculator();

        // Use reflection to access private $year property
        $reflection = new \ReflectionClass($calculator);
        $year_property = $reflection->getProperty('year');
        $year_property->setAccessible(true);
        $year = $year_property->getValue($calculator);

        $this->assertEquals(2025, $year,
            'Constructor should use get_latest_data_year() by default');
    }

    /**
     * Test that constructor accepts explicit year parameter.
     */
    public function test_constructor_accepts_explicit_year() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2024);

        $reflection = new \ReflectionClass($calculator);
        $year_property = $reflection->getProperty('year');
        $year_property->setAccessible(true);
        $year = $year_property->getValue($calculator);

        $this->assertEquals(2024, $year,
            'Constructor should use explicit year when provided');
    }

    /**
     * Test that weights array sums to 1.0 for default weights.
     */
    public function test_default_weights_sum_to_one() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2025);

        $reflection = new \ReflectionClass($calculator);
        $weights_property = $reflection->getProperty('weights');
        $weights_property->setAccessible(true);
        $weights = $weights_property->getValue($calculator);

        $sum = array_sum($weights);

        $this->assertEqualsWithDelta(1.0, $sum, 0.001,
            'Default weights should sum to 1.0 (100%)');
    }

    /**
     * Test that elementary weights sum to 1.0.
     */
    public function test_elementary_weights_sum_to_one() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2025);

        $reflection = new \ReflectionClass($calculator);
        $weights_property = $reflection->getProperty('weights_elementary');
        $weights_property->setAccessible(true);
        $weights = $weights_property->getValue($calculator);

        $sum = array_sum($weights);

        $this->assertEqualsWithDelta(1.0, $sum, 0.001,
            'Elementary weights should sum to 1.0 (100%)');
    }

    /**
     * Test that MCAS weight is the highest factor for default weights.
     *
     * Per v0.6.35 weight rebalancing, MCAS should be 40% (highest).
     */
    public function test_mcas_is_highest_weighted_factor() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2025);

        $reflection = new \ReflectionClass($calculator);
        $weights_property = $reflection->getProperty('weights');
        $weights_property->setAccessible(true);
        $weights = $weights_property->getValue($calculator);

        $max_weight = max($weights);
        $mcas_weight = $weights['mcas_proficiency'];

        $this->assertEquals($max_weight, $mcas_weight,
            'MCAS proficiency should have the highest weight');
        $this->assertEquals(0.40, $mcas_weight,
            'MCAS weight should be 40%');
    }

    /**
     * Test that elementary schools have higher MCAS weight than default.
     */
    public function test_elementary_has_higher_mcas_weight() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2025);

        $reflection = new \ReflectionClass($calculator);

        $default_weights = $reflection->getProperty('weights');
        $default_weights->setAccessible(true);

        $elementary_weights = $reflection->getProperty('weights_elementary');
        $elementary_weights->setAccessible(true);

        $default_mcas = $default_weights->getValue($calculator)['mcas_proficiency'];
        $elementary_mcas = $elementary_weights->getValue($calculator)['mcas_proficiency'];

        $this->assertGreaterThan($default_mcas, $elementary_mcas,
            'Elementary MCAS weight (45%) should be higher than default (40%)');
    }

    /**
     * Test that elementary schools have zero weight for N/A factors.
     */
    public function test_elementary_excludes_non_applicable_factors() {
        $calculator = new \BMN_Schools_Ranking_Calculator(2025);

        $reflection = new \ReflectionClass($calculator);
        $weights_property = $reflection->getProperty('weights_elementary');
        $weights_property->setAccessible(true);
        $weights = $weights_property->getValue($calculator);

        // These factors are N/A for elementary schools
        $na_factors = ['graduation_rate', 'masscore', 'ap_performance', 'college_outcomes'];

        foreach ($na_factors as $factor) {
            $this->assertEquals(0.00, $weights[$factor],
                "{$factor} should have 0% weight for elementary schools");
        }
    }

    /**
     * Test lock key constant is defined.
     */
    public function test_lock_key_constant_defined() {
        $this->assertTrue(
            defined('\BMN_Schools_Ranking_Calculator::LOCK_KEY'),
            'LOCK_KEY constant should be defined'
        );
        $this->assertEquals(
            'bmn_schools_ranking_lock',
            \BMN_Schools_Ranking_Calculator::LOCK_KEY
        );
    }

    /**
     * Test lock duration constant is defined.
     */
    public function test_lock_duration_constant_defined() {
        $this->assertTrue(
            defined('\BMN_Schools_Ranking_Calculator::LOCK_DURATION'),
            'LOCK_DURATION constant should be defined'
        );
        $this->assertEquals(600, \BMN_Schools_Ranking_Calculator::LOCK_DURATION,
            'Lock duration should be 600 seconds (10 minutes)');
    }
}
