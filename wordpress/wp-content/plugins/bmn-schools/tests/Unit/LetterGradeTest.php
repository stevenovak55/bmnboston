<?php
/**
 * Letter Grade Function Unit Tests
 *
 * Tests for letter grade conversion functions, specifically focusing on:
 * - Null coalescing for unrated schools (Critical Pitfall #12)
 * - Score vs Percentile conversion (Critical Pitfall #13)
 *
 * @package BMN_Schools\Tests\Unit
 * @since 0.6.38
 */

namespace BMN_Schools\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/BMN_Schools_Unit_TestCase.php';

// Define the functions being tested if they don't exist
// These are normally defined in the theme or REST API
if (!function_exists('bmn_get_letter_grade_from_percentile')) {
    /**
     * Get letter grade from percentile rank.
     *
     * Percentile-based grading (v0.6.1+):
     * - A+ = 90-100 percentile (top 10%)
     * - A  = 80-89 percentile
     * - A- = 70-79 percentile
     * - B+ = 60-69 percentile
     * - B  = 50-59 percentile
     * - B- = 40-49 percentile
     * - C+ = 30-39 percentile
     * - C  = 20-29 percentile
     * - C- = 10-19 percentile
     * - D  = 5-9 percentile
     * - F  = 0-4 percentile
     *
     * @param float|null $percentile Percentile rank (0-100) or null for unrated
     * @return string Letter grade or 'N/A' for null
     */
    function bmn_get_letter_grade_from_percentile($percentile) {
        if ($percentile === null) {
            return 'N/A';
        }

        $percentile = (float) $percentile;

        if ($percentile >= 90) return 'A+';
        if ($percentile >= 80) return 'A';
        if ($percentile >= 70) return 'A-';
        if ($percentile >= 60) return 'B+';
        if ($percentile >= 50) return 'B';
        if ($percentile >= 40) return 'B-';
        if ($percentile >= 30) return 'C+';
        if ($percentile >= 20) return 'C';
        if ($percentile >= 10) return 'C-';
        if ($percentile >= 5) return 'D';
        return 'F';
    }
}

if (!function_exists('bmn_get_letter_grade_from_score')) {
    /**
     * Get letter grade from a raw composite score.
     *
     * This function calculates the actual percentile for a given score
     * based on the score distribution for that school level.
     *
     * This is different from bmn_get_letter_grade_from_percentile() which
     * expects an already-calculated percentile.
     *
     * @param float|null $score Raw composite score
     * @param string $level School level ('elementary', 'middle', 'high', 'all')
     * @return string Letter grade
     */
    function bmn_get_letter_grade_from_score($score, $level = 'all') {
        if ($score === null) {
            return 'N/A';
        }

        // Approximate percentile based on typical score distributions
        // These are simplified for testing - real implementation queries database
        $benchmarks = [
            'elementary' => [
                'p90' => 60.0,  // 90th percentile score
                'p80' => 55.0,
                'p70' => 50.0,
                'p60' => 45.0,
                'p50' => 40.0,
                'p40' => 35.0,
                'p30' => 30.0,
                'p20' => 25.0,
                'p10' => 20.0,
                'p5'  => 15.0,
            ],
            'middle' => [
                'p90' => 65.0,
                'p80' => 58.0,
                'p70' => 52.0,
                'p60' => 46.0,
                'p50' => 40.0,
                'p40' => 34.0,
                'p30' => 28.0,
                'p20' => 22.0,
                'p10' => 16.0,
                'p5'  => 10.0,
            ],
            'high' => [
                'p90' => 70.0,
                'p80' => 62.0,
                'p70' => 55.0,
                'p60' => 48.0,
                'p50' => 42.0,
                'p40' => 36.0,
                'p30' => 30.0,
                'p20' => 24.0,
                'p10' => 18.0,
                'p5'  => 12.0,
            ],
            'all' => [
                'p90' => 65.0,
                'p80' => 58.0,
                'p70' => 52.0,
                'p60' => 46.0,
                'p50' => 40.0,
                'p40' => 34.0,
                'p30' => 28.0,
                'p20' => 22.0,
                'p10' => 16.0,
                'p5'  => 10.0,
            ],
        ];

        $bench = $benchmarks[$level] ?? $benchmarks['all'];

        // Calculate percentile based on score
        if ($score >= $bench['p90']) return 'A+';
        if ($score >= $bench['p80']) return 'A';
        if ($score >= $bench['p70']) return 'A-';
        if ($score >= $bench['p60']) return 'B+';
        if ($score >= $bench['p50']) return 'B';
        if ($score >= $bench['p40']) return 'B-';
        if ($score >= $bench['p30']) return 'C+';
        if ($score >= $bench['p20']) return 'C';
        if ($score >= $bench['p10']) return 'C-';
        if ($score >= $bench['p5']) return 'D';
        return 'F';
    }
}

/**
 * Letter Grade Test Class
 */
class LetterGradeTest extends BMN_Schools_Unit_TestCase {

    /**
     * Test that NULL percentile returns 'N/A', not 'F'.
     *
     * Critical Pitfall #12: Null Coalescing for Unrated Schools
     *
     * Private schools (like Phillips Academy) don't have MCAS rankings.
     * They should show "N/A", not "F".
     */
    public function test_null_percentile_returns_na_not_f() {
        $grade = bmn_get_letter_grade_from_percentile(null);

        $this->assertEquals('N/A', $grade,
            'NULL percentile should return "N/A", not a letter grade');
        $this->assertNotEquals('F', $grade,
            'NULL percentile should NOT return "F"');
    }

    /**
     * Test that zero percentile returns 'F'.
     *
     * Zero is a valid percentile (0th percentile = worst) and should return F.
     * This is different from NULL which means "no data".
     */
    public function test_zero_percentile_returns_f() {
        $grade = bmn_get_letter_grade_from_percentile(0);

        $this->assertEquals('F', $grade,
            'Zero percentile (0th percentile) should return "F"');
    }

    /**
     * Test that integer zero percentile returns 'F'.
     */
    public function test_integer_zero_percentile_returns_f() {
        $grade = bmn_get_letter_grade_from_percentile((int) 0);

        $this->assertEquals('F', $grade,
            'Integer zero should return "F", not "N/A"');
    }

    /**
     * Test percentile grade boundaries.
     *
     * Verifies the percentile-based grading system (v0.6.1+).
     */
    public function test_percentile_grade_boundaries() {
        $test_cases = [
            // [percentile, expected_grade]
            [100, 'A+'],
            [95, 'A+'],
            [90, 'A+'],
            [89.9, 'A'],
            [85, 'A'],
            [80, 'A'],
            [79.9, 'A-'],
            [75, 'A-'],
            [70, 'A-'],
            [69.9, 'B+'],
            [65, 'B+'],
            [60, 'B+'],
            [59.9, 'B'],
            [55, 'B'],
            [50, 'B'],
            [49.9, 'B-'],
            [45, 'B-'],
            [40, 'B-'],
            [39.9, 'C+'],
            [35, 'C+'],
            [30, 'C+'],
            [29.9, 'C'],
            [25, 'C'],
            [20, 'C'],
            [19.9, 'C-'],
            [15, 'C-'],
            [10, 'C-'],
            [9.9, 'D'],
            [7, 'D'],
            [5, 'D'],
            [4.9, 'F'],
            [2, 'F'],
            [0, 'F'],
        ];

        foreach ($test_cases as [$percentile, $expected]) {
            $actual = bmn_get_letter_grade_from_percentile($percentile);
            $this->assertEquals($expected, $actual,
                "Percentile {$percentile} should return grade {$expected}, got {$actual}");
        }
    }

    /**
     * Test that score-based grade differs from direct percentile interpretation.
     *
     * Critical Pitfall #13: Score vs Percentile for Letter Grades
     *
     * A score of 64.9 is in the 97th percentile for elementary schools (A+),
     * but passing it directly to bmn_get_letter_grade_from_percentile() would
     * return B+ because 64.9 < 70.
     */
    public function test_high_score_converts_to_high_grade() {
        $score = 64.9;

        // Using score-based function (correct)
        $correct_grade = bmn_get_letter_grade_from_score($score, 'elementary');

        // Using percentile function directly (incorrect approach)
        $incorrect_grade = bmn_get_letter_grade_from_percentile($score);

        // The score-based grade should be higher
        $this->assertEquals('A+', $correct_grade,
            'Score of 64.9 for elementary should be A+ (97th percentile)');

        $this->assertEquals('B+', $incorrect_grade,
            'Directly passing 64.9 to percentile function returns B+ (incorrect approach)');

        $this->assertNotEquals($correct_grade, $incorrect_grade,
            'Score-based and direct percentile approaches should give different results for high scores');
    }

    /**
     * Test that district averages should use score-based function.
     *
     * When displaying district average grades, use bmn_get_letter_grade_from_score(),
     * NOT bmn_get_letter_grade_from_percentile().
     */
    public function test_district_average_uses_score_function() {
        // Simulate a district with high-performing elementary schools
        $elementary_avg = 64.9;  // This is a very high average

        $correct = bmn_get_letter_grade_from_score($elementary_avg, 'elementary');
        $incorrect = bmn_get_letter_grade_from_percentile($elementary_avg);

        $this->assertEquals('A+', $correct,
            'District average of 64.9 elementary should show A+');
        $this->assertNotEquals('B+', $correct,
            'District average should NOT show B+');
    }

    /**
     * Test that score function handles NULL correctly.
     */
    public function test_score_function_handles_null() {
        $grade = bmn_get_letter_grade_from_score(null, 'elementary');

        $this->assertEquals('N/A', $grade,
            'NULL score should return "N/A"');
    }

    /**
     * Test different school levels have different score benchmarks.
     */
    public function test_score_benchmarks_vary_by_level() {
        $score = 60.0;

        $elementary_grade = bmn_get_letter_grade_from_score($score, 'elementary');
        $high_grade = bmn_get_letter_grade_from_score($score, 'high');

        // 60.0 is above 90th percentile for elementary but below for high school
        $this->assertEquals('A+', $elementary_grade,
            '60.0 should be A+ for elementary');

        // High school has higher score requirements
        $this->assertNotEquals('A+', $high_grade,
            '60.0 should NOT be A+ for high school');
    }

    /**
     * Test the null coalescing pattern that caused the bug.
     *
     * This tests the actual code pattern that was wrong:
     * $ranking->percentile_rank ?? 0  (WRONG - shows F for unrated)
     * $ranking->percentile_rank ?? null (CORRECT - shows N/A for unrated)
     */
    public function test_null_coalescing_patterns() {
        // Simulate a school with no ranking data
        $ranking = (object) ['percentile_rank' => null];

        // WRONG pattern: ?? 0
        $wrong_grade = bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? 0);

        // CORRECT pattern: ?? null
        $correct_grade = bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? null);

        $this->assertEquals('F', $wrong_grade,
            'Using ?? 0 for null percentile returns F (wrong)');
        $this->assertEquals('N/A', $correct_grade,
            'Using ?? null for null percentile returns N/A (correct)');
    }

    /**
     * Test that ranked schools still work with null coalescing.
     *
     * Schools WITH rankings should not be affected by the fix.
     */
    public function test_ranked_schools_unaffected_by_null_fix() {
        // Simulate a school with valid ranking
        $ranking = (object) ['percentile_rank' => 85];

        // Both patterns should work for schools WITH data
        $with_zero_fallback = bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? 0);
        $with_null_fallback = bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? null);

        $this->assertEquals($with_zero_fallback, $with_null_fallback,
            'Schools with rankings should get same grade regardless of null coalescing pattern');
        $this->assertEquals('A', $with_null_fallback,
            '85th percentile should be A');
    }
}
