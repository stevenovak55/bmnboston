<?php
/**
 * Base Unit Test Case for BMN Schools Plugin
 *
 * Provides common setup/teardown and helper methods for unit tests.
 *
 * @package BMN_Schools\Tests\Unit
 * @since 0.6.38
 */

namespace BMN_Schools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Base Unit Test Case
 */
abstract class BMN_Schools_Unit_TestCase extends TestCase {

    /**
     * Mock wpdb instance
     *
     * @var \MockWPDB
     */
    protected $wpdb;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Reset global test data
        bmn_schools_reset_test_data();

        // Create mock wpdb
        $this->wpdb = new \MockWPDB();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void {
        parent::tearDown();

        // Clean up
        bmn_schools_reset_test_data();
    }

    /**
     * Set mock database result for get_var queries
     *
     * @param string $sql_pattern SQL pattern to match
     * @param mixed $result Result to return
     */
    protected function mockGetVar($sql_pattern, $result) {
        bmn_set_mock_wpdb_result('get_var', $sql_pattern, $result);
    }

    /**
     * Set mock database result for get_row queries
     *
     * @param string $sql_pattern SQL pattern to match
     * @param object|null $result Result to return
     */
    protected function mockGetRow($sql_pattern, $result) {
        bmn_set_mock_wpdb_result('get_row', $sql_pattern, $result);
    }

    /**
     * Set mock database result for get_results queries
     *
     * @param string $sql_pattern SQL pattern to match
     * @param array $result Results to return
     */
    protected function mockGetResults($sql_pattern, $result) {
        bmn_set_mock_wpdb_result('get_results', $sql_pattern, $result);
    }

    /**
     * Create a mock school object
     *
     * @param array $data School data overrides
     * @return object
     */
    protected function createMockSchool($data = []) {
        $defaults = [
            'id' => 1,
            'name' => 'Test School',
            'city' => 'Boston',
            'state' => 'MA',
            'level' => 'High School',
            'grades' => '9-12',
            'school_type' => 'public',
            'latitude' => 42.3601,
            'longitude' => -71.0589,
        ];

        return (object) array_merge($defaults, $data);
    }

    /**
     * Create a mock ranking object
     *
     * @param array $data Ranking data overrides
     * @return object
     */
    protected function createMockRanking($data = []) {
        $defaults = [
            'id' => 1,
            'school_id' => 1,
            'year' => 2025,
            'composite_score' => 75.5,
            'percentile_rank' => 85,
            'state_rank' => 150,
            'letter_grade' => 'A',
            'category_total' => 1000,
            'prior_rank' => 160,
            'rank_change' => 10,
        ];

        return (object) array_merge($defaults, $data);
    }

    /**
     * Create a mock district object
     *
     * @param array $data District data overrides
     * @return object
     */
    protected function createMockDistrict($data = []) {
        $defaults = [
            'id' => 1,
            'name' => 'Test District',
            'district_code' => '00010000',
            'total_schools' => 10,
            'total_students' => 5000,
        ];

        return (object) array_merge($defaults, $data);
    }
}
