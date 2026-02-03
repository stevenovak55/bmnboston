<?php
/**
 * Base Unit Test Case for SN Appointment Booking Plugin
 *
 * @package SN_Appointment_Booking\Tests\Unit
 * @since 1.9.4
 */

namespace SNAB\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Base Unit Test Case
 */
abstract class SNAB_Unit_TestCase extends TestCase {

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
        snab_reset_test_data();
        $this->wpdb = new \MockWPDB();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void {
        parent::tearDown();
        snab_reset_test_data();
    }

    /**
     * Set mock database result for get_var
     */
    protected function mockGetVar($sql_pattern, $result) {
        snab_set_mock_wpdb_result('get_var', $sql_pattern, $result);
    }

    /**
     * Set mock database result for get_row
     */
    protected function mockGetRow($sql_pattern, $result) {
        snab_set_mock_wpdb_result('get_row', $sql_pattern, $result);
    }

    /**
     * Set mock database result for get_results
     */
    protected function mockGetResults($sql_pattern, $result) {
        snab_set_mock_wpdb_result('get_results', $sql_pattern, $result);
    }

    /**
     * Set current test user
     */
    protected function setCurrentUser($user_id, $capabilities = []) {
        snab_set_test_user($user_id, $capabilities);
    }

    /**
     * Create mock staff member
     */
    protected function createMockStaff($data = []) {
        return (object) array_merge([
            'id' => 1,
            'user_id' => 10,
            'name' => 'Test Staff',
            'email' => 'staff@test.com',
            'phone' => '555-1234',
            'bio' => 'Test bio',
            'avatar_url' => null,
            'google_refresh_token' => null,
            'is_active' => 1,
        ], $data);
    }

    /**
     * Create mock appointment
     */
    protected function createMockAppointment($data = []) {
        return (object) array_merge([
            'id' => 1,
            'user_id' => 20,  // Client who booked
            'staff_id' => 1,  // Staff assigned to
            'service_id' => 1,
            'appointment_date' => '2026-01-20',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'confirmed',
            'notes' => null,
            'guest_name' => null,
            'guest_email' => null,
            'guest_phone' => null,
            'created_at' => '2026-01-14 10:00:00',
        ], $data);
    }

    /**
     * Create mock appointment type
     */
    protected function createMockAppointmentType($data = []) {
        return (object) array_merge([
            'id' => 1,
            'name' => 'Property Showing',
            'duration' => 60,
            'buffer_before' => 15,
            'buffer_after' => 15,
            'is_active' => 1,
        ], $data);
    }
}
