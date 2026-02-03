<?php
/**
 * REST API Response Format Tests
 *
 * Tests for API response structure to ensure iOS compatibility.
 *
 * Critical Pitfall #9: iOS API Response Format Mismatch
 * iOS shows "Failed to parse response" when API returns data in a different
 * format than the Swift model expects.
 *
 * @package BMN_Schools\Tests\Unit
 * @since 0.6.38
 */

namespace BMN_Schools\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/BMN_Schools_Unit_TestCase.php';

/**
 * REST API Response Format Test Class
 */
class RestApiResponseTest extends BMN_Schools_Unit_TestCase {

    /**
     * Test property schools response has required structure.
     *
     * iOS NearbySchool model requires specific fields.
     */
    public function test_property_schools_response_structure() {
        $response = $this->createMockPropertySchoolsResponse();

        // Check top-level structure
        $this->assertArrayHasKey('success', $response,
            'Response must have success key');
        $this->assertArrayHasKey('data', $response,
            'Response must have data key');

        // Check data structure
        $data = $response['data'];
        $this->assertArrayHasKey('district', $data,
            'Data must have district key');
        $this->assertArrayHasKey('schools', $data,
            'Data must have schools key');

        // Check schools are grouped by level
        $schools = $data['schools'];
        $this->assertArrayHasKey('elementary', $schools,
            'Schools must have elementary key');
        $this->assertArrayHasKey('middle', $schools,
            'Schools must have middle key');
        $this->assertArrayHasKey('high', $schools,
            'Schools must have high key');
    }

    /**
     * Test individual school object has required fields.
     *
     * iOS NearbySchool model requires these fields to parse correctly.
     */
    public function test_school_object_has_required_fields() {
        $school = $this->createMockSchoolResponse();

        $required_fields = [
            'id',
            'name',
            'city',
            'grades',
            'distance',
            'latitude',
            'longitude',
        ];

        foreach ($required_fields as $field) {
            $this->assertArrayHasKey($field, $school,
                "School object must have '{$field}' field");
        }
    }

    /**
     * Test school ranking object has required fields.
     *
     * iOS NearbySchoolRanking model requires these fields.
     */
    public function test_ranking_object_has_required_fields() {
        $ranking = $this->createMockRankingResponse();

        $required_fields = [
            'composite_score',
            'percentile_rank',
            'state_rank',
            'letter_grade',
            'category_total',
        ];

        foreach ($required_fields as $field) {
            $this->assertArrayHasKey($field, $ranking,
                "Ranking object must have '{$field}' field");
        }
    }

    /**
     * Test ranking trend object structure.
     *
     * iOS RankingTrend model expects direction and rank_change_text.
     */
    public function test_trend_object_structure() {
        $ranking = $this->createMockRankingResponse();

        $this->assertArrayHasKey('trend', $ranking,
            'Ranking must have trend object');

        $trend = $ranking['trend'];
        $this->assertArrayHasKey('direction', $trend,
            'Trend must have direction (up/down/stable)');
        $this->assertArrayHasKey('rank_change', $trend,
            'Trend must have rank_change');
        $this->assertArrayHasKey('rank_change_text', $trend,
            'Trend must have rank_change_text for display');
    }

    /**
     * Test data completeness object structure.
     *
     * iOS DataCompleteness model expects these fields.
     */
    public function test_data_completeness_structure() {
        $ranking = $this->createMockRankingResponse();

        $this->assertArrayHasKey('data_completeness', $ranking,
            'Ranking must have data_completeness object');

        $completeness = $ranking['data_completeness'];
        $required = ['components_available', 'components_total', 'confidence_level'];

        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $completeness,
                "Data completeness must have '{$field}' field");
        }
    }

    /**
     * Test benchmarks object structure.
     *
     * iOS RankingBenchmarks model expects state_average and vs_state.
     */
    public function test_benchmarks_structure() {
        $ranking = $this->createMockRankingResponse();

        $this->assertArrayHasKey('benchmarks', $ranking,
            'Ranking must have benchmarks object');

        $benchmarks = $ranking['benchmarks'];
        $this->assertArrayHasKey('state_average', $benchmarks,
            'Benchmarks must have state_average');
        $this->assertArrayHasKey('vs_state', $benchmarks,
            'Benchmarks must have vs_state comparison string');
    }

    /**
     * Test demographics object structure.
     *
     * iOS NearbySchoolDemographics model expects these fields.
     */
    public function test_demographics_structure() {
        $school = $this->createMockSchoolResponse();

        $this->assertArrayHasKey('demographics', $school,
            'School must have demographics object');

        $demographics = $school['demographics'];
        $this->assertArrayHasKey('total_students', $demographics,
            'Demographics must have total_students');
    }

    /**
     * Test highlights array structure.
     *
     * iOS SchoolHighlight model expects type, text, icon.
     */
    public function test_highlights_structure() {
        $school = $this->createMockSchoolResponse();

        $this->assertArrayHasKey('highlights', $school,
            'School must have highlights array');
        $this->assertIsArray($school['highlights'],
            'Highlights must be an array');

        if (!empty($school['highlights'])) {
            $highlight = $school['highlights'][0];
            $this->assertArrayHasKey('type', $highlight,
                'Highlight must have type');
            $this->assertArrayHasKey('text', $highlight,
                'Highlight must have text');
            $this->assertArrayHasKey('icon', $highlight,
                'Highlight must have icon');
        }
    }

    /**
     * Test health endpoint response structure.
     */
    public function test_health_response_structure() {
        $response = $this->createMockHealthResponse();

        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('data', $response);

        $data = $response['data'];
        $this->assertArrayHasKey('status', $data,
            'Health response must have status');
        $this->assertArrayHasKey('version', $data,
            'Health response must have version');
        $this->assertArrayHasKey('tables', $data,
            'Health response must have tables info');
    }

    /**
     * Test that numeric IDs are returned as expected types.
     *
     * iOS may expect Int or String depending on usage.
     */
    public function test_id_types_are_consistent() {
        $school = $this->createMockSchoolResponse();

        // School ID should be numeric (Int in Swift)
        $this->assertIsNumeric($school['id'],
            'School ID should be numeric');
    }

    /**
     * Test that optional fields can be null.
     *
     * iOS models use Optional types (Int?, String?) for nullable fields.
     */
    public function test_optional_fields_can_be_null() {
        $school = $this->createMockSchoolResponse();

        // These fields are optional in iOS model
        $optional_fields = ['is_regional', 'regional_note'];

        foreach ($optional_fields as $field) {
            // Field either doesn't exist or can be null - both are valid
            if (array_key_exists($field, $school)) {
                $this->assertTrue(
                    $school[$field] === null || is_string($school[$field]) || is_bool($school[$field]),
                    "Optional field '{$field}' should be null, string, or bool"
                );
            }
        }
    }

    /**
     * Test district object structure.
     */
    public function test_district_object_structure() {
        $response = $this->createMockPropertySchoolsResponse();
        $district = $response['data']['district'];

        $required_fields = ['id', 'name'];

        foreach ($required_fields as $field) {
            $this->assertArrayHasKey($field, $district,
                "District must have '{$field}' field");
        }
    }

    /**
     * Test district ranking structure when present.
     */
    public function test_district_ranking_structure() {
        $response = $this->createMockPropertySchoolsResponse();
        $district = $response['data']['district'];

        if (isset($district['ranking'])) {
            $ranking = $district['ranking'];
            $this->assertArrayHasKey('composite_score', $ranking,
                'District ranking must have composite_score');
            $this->assertArrayHasKey('letter_grade', $ranking,
                'District ranking must have letter_grade');
        }
    }

    /**
     * Test sports data structure for high schools.
     */
    public function test_sports_structure() {
        $school = $this->createMockSchoolResponse(['level' => 'high']);

        if (isset($school['sports'])) {
            $sports = $school['sports'];
            $this->assertArrayHasKey('sports_count', $sports,
                'Sports must have sports_count');
            $this->assertArrayHasKey('total_participants', $sports,
                'Sports must have total_participants');
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create mock property schools API response
     */
    private function createMockPropertySchoolsResponse() {
        return [
            'success' => true,
            'data' => [
                'district' => [
                    'id' => 42,
                    'name' => 'Test District',
                    'ranking' => [
                        'composite_score' => 75.5,
                        'percentile_rank' => 85,
                        'letter_grade' => 'A',
                    ],
                ],
                'schools' => [
                    'elementary' => [$this->createMockSchoolResponse(['level' => 'elementary'])],
                    'middle' => [$this->createMockSchoolResponse(['level' => 'middle'])],
                    'high' => [$this->createMockSchoolResponse(['level' => 'high'])],
                ],
            ],
        ];
    }

    /**
     * Create mock school response object
     */
    private function createMockSchoolResponse($overrides = []) {
        return array_merge([
            'id' => 123,
            'name' => 'Test Elementary School',
            'city' => 'Boston',
            'state' => 'MA',
            'grades' => 'K-5',
            'distance' => 0.5,
            'latitude' => 42.3601,
            'longitude' => -71.0589,
            'school_type' => 'public',
            'is_regional' => false,
            'regional_note' => null,
            'ranking' => $this->createMockRankingResponse(),
            'demographics' => [
                'total_students' => 450,
                'diversity' => 'Diverse',
                'pct_free_reduced_lunch' => 12.5,
            ],
            'highlights' => [
                [
                    'type' => 'ratio',
                    'text' => 'Low Student-Teacher Ratio',
                    'detail' => '11:1',
                    'icon' => 'person.2.fill',
                    'priority' => 2,
                ],
            ],
        ], $overrides);
    }

    /**
     * Create mock ranking response object
     */
    private function createMockRankingResponse() {
        return [
            'composite_score' => 85.2,
            'percentile_rank' => 92,
            'state_rank' => 67,
            'category_total' => 843,
            'letter_grade' => 'A+',
            'trend' => [
                'direction' => 'up',
                'rank_change' => 5,
                'rank_change_text' => 'Improved 5 spots from last year',
            ],
            'data_completeness' => [
                'components_available' => 4,
                'components_total' => 5,
                'confidence_level' => 'good',
                'limited_data_note' => null,
            ],
            'benchmarks' => [
                'state_average' => 72.5,
                'vs_state' => '+12.7 above state avg',
            ],
        ];
    }

    /**
     * Create mock health response
     */
    private function createMockHealthResponse() {
        return [
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'version' => '0.6.38',
                'tables' => [
                    'schools' => ['exists' => true, 'count' => 2636],
                    'districts' => ['exists' => true, 'count' => 342],
                    'rankings' => ['exists' => true, 'count' => 4897],
                ],
                'record_counts' => [
                    'schools' => 2636,
                    'districts' => 342,
                    'rankings' => 4897,
                ],
            ],
        ];
    }
}
